<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;

/**
 * The bank row a buy takes money out of, and a sell pays into; a brokerage holds no
 * balance, so without it the money moved nowhere.
 *
 * Linked both ways by paired_transaction_id, and rewritten from the trade on every edit
 * rather than locking it: correcting a price is the ordinary edit. On the trade date,
 * not T+2, with the trade's status.
 *
 * meta_data.no_cash skips it, for money that moved outside these accounts.
 */
class TradeCash
{
    /**
     * Inside the write's database transaction, after the bag is saved. $wasType is the
     * type before an edit, since a buy edited into something else leaves a cash row to
     * remove.
     *
     * Only a brokerage row: a card payment's pair is half a settlement, not cash to remove.
     */
    public static function sync(Transaction $trade, ?string $wasType = null): void
    {
        $wanted = TransactionType::from($trade->type)->derivesAmount()
            || ($wasType !== null && TransactionType::from($wasType)->derivesAmount());

        if (! $wanted) {
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

    /** Asked of a row's partner, it says the row is that trade's cash side. */
    public static function isTrade(?Transaction $row): bool
    {
        return $row !== null && TransactionType::from($row->type)->derivesAmount();
    }

    /** "Buy 10 NVDA [Broker]". */
    public static function describe(Transaction $trade): string
    {
        $meta = $trade->meta?->meta?->getArrayCopy() ?? [];

        $symbol = strtoupper(trim((string) ($meta['symbol'] ?? '')));
        $account = $trade->account?->name;

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

        if (! TransactionType::from($trade->type)->derivesAmount()) {
            return null;
        }

        // Null, so sync() removes a cash row from a trade flagged since. `=== true`, not
        // filled(): filled(false) is true, and would drop the cash of a trade that moved money.
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
