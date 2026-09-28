<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;

/**
 * The cash side of a brokerage row: the row in the settlement account that a buy takes
 * money out of, and that a sell or a dividend pays into.
 *
 * A brokerage holds no balance -- AccountType::hasBalance() -- so without this row a
 * trade moved no money anywhere, and the bank it settles through read as though
 * nothing had been bought or sold. The same is true of a dividend: it arrives at the
 * brokerage and stops there, and the bank it was actually paid into never learns of it.
 * Which rows get one is TransactionType::needsCashSide()'s answer, not this class's --
 * the form needs the same answer per account type to know which fields to show.
 *
 * It is written beside the row, not by the user, and linked to it by
 * paired_transaction_id both ways, the link a card settlement uses: destroy() deletes
 * the pair together, and the cash row's figures are locked, since they are the trade's
 * and follow it.
 *
 * A trade or a dividend may opt out with meta_data.no_cash, for a position back-dated
 * from before the settlement account existed. The shares are the position either way --
 * Positions replays the trades, not the cash -- so what is skipped is the invention of
 * a bank row for money that moved outside these accounts.
 *
 * The trade is the record and this follows it. An edit to the trade rewrites the cash
 * row -- its amount, date, currency, status, and the bank if the trade moved to another
 * brokerage -- rather than locking the trade, because correcting a trade's price is
 * the ordinary edit and the cash has no figures of its own to protect.
 *
 * On the trade date, not T+2. The status is the trade's, so a trade entered as pending
 * until it settles leaves its cash pending too, out of the bank's balance until then.
 */
class TradeCash
{
    /**
     * Write, update, move or remove a cash row to match the row as stored.
     *
     * Inside the write's database transaction, after the trade and its bag are saved: a
     * brokerage row without its cash, or cash without its row, is the state this exists
     * to prevent.
     *
     * $wasType and $wasAccountId are what it was, for a row that has just changed either.
     * Both are needed: a type says whether a cash side was wanted, and only an account
     * says whether it could be -- a deposit on a brokerage has one and a deposit on a bank
     * does not, and they are the same type. Without the previous account, a dividend moved
     * onto a bank account would leave its cash row behind, pointing at a brokerage the
     * dividend no longer belongs to.
     *
     * Nothing outside a brokerage is ever touched. A card payment is paired with its bank
     * withdrawal, and treating that pairing as cash to remove would delete half a
     * settlement on a description fix.
     */
    public static function sync(
        Transaction $trade,
        ?string $wasType = null,
        ?int $wasAccountId = null
    ): void {
        if (! self::wantedCashSide($trade, $wasType, $wasAccountId)) {
            return;
        }

        $trade->unsetRelation('meta')->unsetRelation('account')->load('meta', 'account');

        $pairedId = $trade->meta?->meta['paired_transaction_id'] ?? null;
        $cash = $pairedId === null ? null : Transaction::with('meta')->find($pairedId);

        $bank = self::bankFor($trade);

        if ($bank === null) {
            // No longer a buy or sell, or its brokerage names no bank: no cash side.
            if ($cash !== null) {
                $cash->meta()->delete();
                $cash->delete();
                self::link($trade, null);
            }

            return;
        }

        $attributes = [
            'account_id' => $bank->id,
            'category_id' => null,
            'date' => $trade->date,
            // A withdrawal for the money a buy takes out, a deposit for what a sell
            // pays in. A transfer used to be its own type for the buy so that paying a
            // card would not count as spending; that distinction is gone with the
            // other cash types and nothing reads it now.
            'type' => $trade->type === TransactionType::Buy->value
                ? TransactionType::Withdraw->value
                : TransactionType::Deposit->value,
            'description' => self::describe($trade),
            'amount' => $trade->amount,
            'ccy' => $trade->ccy,
            'status' => $trade->status,
        ];

        if ($cash === null) {
            $cash = Transaction::create($attributes);
            $cash->meta()->create(['meta' => ['paired_transaction_id' => $trade->id]]);
            self::link($trade, $cash->id);

            return;
        }

        $cash->update($attributes);
    }

    /**
     * Whether a row is a trade or a dividend -- asked of a row's partner, it says the row
     * is that cash side.
     *
     * Not `isTrade()` any more, and the name is the reason: a dividend is a deposit and
     * not a trade, and calling it one would have every caller reading it as a question
     * about the type rather than about whether a row beside it should exist.
     *
     * False for null and for a row on anything but a brokerage, so a card payment's pair is
     * not mistaken for a cash side.
     */
    public static function hasCashSide(?Transaction $row): bool
    {
        return $row?->account?->type === AccountType::Security->value
            && TransactionType::from($row->type)->needsCashSide(AccountType::Security);
    }

    /**
     * Whether this row should have a cash row written, updated or removed by this sync.
     *
     * Now, or before this edit: a row that has just moved off a brokerage owes the
     * removal of the cash row it left there, and the only record of that is what it was.
     */
    private static function wantedCashSide(
        Transaction $row,
        ?string $wasType,
        ?int $wasAccountId
    ): bool {
        if ($row->account?->type === AccountType::Security->value) {
            return TransactionType::from($row->type)->needsCashSide(AccountType::Security);
        }

        if ($wasType === null || $wasAccountId === null) {
            return false;
        }

        // One query, and only on the rare path of a row that has just left a brokerage.
        $wasBrokerage = Account::find($wasAccountId)?->type === AccountType::Security->value;

        return $wasBrokerage && TransactionType::from($wasType)->needsCashSide(AccountType::Security);
    }

    /**
     * The row in words, as the cash row and the refusals name it: "Buy 10 NVDA [Broker]",
     * or "Dividend NVDA [Broker]" for the one with no quantity to name.
     */
    public static function describe(Transaction $trade): string
    {
        $meta = $trade->meta?->meta?->getArrayCopy() ?? [];

        $symbol = strtoupper(trim((string) ($meta['symbol'] ?? '')));
        $account = $trade->account?->name;

        // A dividend is not a quantity of anything, and running the trade's format over it
        // leaves the two spaces where the quantity would be -- a description the delete
        // confirmation quotes back to the user.
        if (! TransactionType::from($trade->type)->derivesAmount()) {
            return sprintf('Dividend %s [%s]', $symbol, $account);
        }

        return sprintf(
            '%s %s %s [%s]',
            ucfirst($trade->type),
            Positions::plain((string) ($meta['quantity'] ?? '')),
            $symbol,
            $account
        );
    }

    /** The bank a brokerage row settles through, or null when it has no cash side. */
    private static function bankFor(Transaction $trade): ?Account
    {
        if ($trade->account?->type !== AccountType::Security->value) {
            return null;
        }

        if (! TransactionType::from($trade->type)->needsCashSide(AccountType::Security)) {
            return null;
        }

        // The trade saying its money moved somewhere these accounts do not hold, so
        // there is no bank to take it out of. Null rather than an early return, so
        // sync() finds the branch it already has for a brokerage naming no bank and
        // takes a cash row back off one that has since been flagged.
        //
        // `=== true` rather than filled(), which reads this backwards: blank(false) is
        // false, so filled(false) is true, and a payload carrying an explicit false --
        // which the form does not send, but a request can -- would drop the cash side of
        // a trade that did move money. Comparing to true fails closed instead.
        if (($trade->meta?->meta['no_cash'] ?? null) === true) {
            return null;
        }

        return $trade->account->settlementAccount();
    }

    /** The trade's own half of the link, merged into its bag beside the trade figures. */
    private static function link(Transaction $trade, ?int $cashId): void
    {
        $meta = $trade->meta?->meta?->getArrayCopy() ?? [];

        if ($cashId === null) {
            unset($meta['paired_transaction_id']);
        } else {
            $meta['paired_transaction_id'] = $cashId;
        }

        $trade->meta()->updateOrCreate(['id' => $trade->meta?->id], ['meta' => $meta]);
    }
}
