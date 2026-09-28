<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;

/**
 * The cash side of a trade: the row in the brokerage's settlement account that a buy
 * takes money out of and a sell pays into.
 *
 * A brokerage holds no balance -- AccountType::hasBalance() -- so without this row a
 * trade moved no money anywhere, and the bank it settles through read as though
 * nothing had been bought or sold. It is written beside the trade, not by the user,
 * and linked to it by paired_transaction_id both ways, the link a card settlement
 * uses: destroy() deletes the pair together, and the cash row's figures are locked,
 * since they are the trade's and follow it.
 *
 * A trade may opt out with meta_data.no_cash, for a position back-dated from before
 * the settlement account existed. The shares are the position either way -- Positions
 * replays the trades, not the cash -- so what is skipped is the invention of a
 * bank row for money that moved outside these accounts.
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
     * Write, update, move or remove a trade's cash row to match the trade as stored.
     *
     * Inside the write's database transaction, after the trade and its bag are saved: a
     * trade without its cash, or cash without its trade, is the state the transaction
     * exists to prevent.
     *
     * Only for a row that is a trade or was one before this edit, which is why update()
     * passes the type it had. Any other row's pairing is not a trade's: a card payment
     * is paired with its bank transfer, and treating that as cash to remove would delete
     * half a settlement on a description fix.
     */
    public static function sync(Transaction $trade, ?string $wasType = null): void
    {
        $isTrade = fn (?string $type) => $type !== null && TransactionType::from($type)->derivesAmount();

        if (! $isTrade($trade->type) && ! $isTrade($wasType)) {
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
     * Whether a row is a trade -- asked of a row's partner, it says the row is that
     * trade's cash side.
     */
    public static function isTrade(?Transaction $row): bool
    {
        return $row !== null && TransactionType::from($row->type)->derivesAmount();
    }

    /**
     * The trade in words, as the cash row and the refusals name it: "Buy 10 NVDA [Broker]".
     */
    public static function describe(Transaction $trade): string
    {
        $meta = $trade->meta?->meta?->getArrayCopy() ?? [];

        return sprintf(
            '%s %s %s [%s]',
            ucfirst($trade->type),
            Positions::plain((string) ($meta['quantity'] ?? '')),
            strtoupper(trim((string) ($meta['symbol'] ?? ''))),
            $trade->account?->name
        );
    }

    /** The bank a trade settles through, or null when it has no cash side. */
    private static function bankFor(Transaction $trade): ?Account
    {
        if (! TransactionType::from($trade->type)->derivesAmount()) {
            return null;
        }

        if ($trade->account?->type !== AccountType::Security->value) {
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
