<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;

/**
 * Money moved between two cash accounts: a withdrawal on one and a deposit on the other,
 * each naming the other by paired_transaction_id, which CashFlow reads as neither income nor
 * spending. Between currencies it is an exchange and the two amounts differ, each in its
 * own account's currency; nothing converts one into the other.
 *
 * Written and rewritten here as a pair, never one half alone, so the two cannot drift: the
 * single-row form refuses a half's figures (TransactionData::figureLock()).
 */
class Transfer
{
    /** Both halves are cash rows, one out and one in: what tells a transfer from a settlement or a trade's cash. */
    public static function isHalf(?Transaction $row, ?Transaction $partner): bool
    {
        if ($row === null || $partner === null) {
            return false;
        }

        $types = [$row->type, $partner->type];
        sort($types);

        return $row->account?->type === AccountType::Cash->value
            && $partner->account?->type === AccountType::Cash->value
            && $types === [TransactionType::Deposit->value, TransactionType::Withdraw->value];
    }

    /**
     * The description a transfer gets when none is given, in the words transfers:pair reads:
     * "TRANSFER TO <account>" out and "TRANSFER FROM <account>" in, or one "EXCHANGE <ccy> TO
     * <ccy> <amount>" on both sides.
     *
     * @return array{0: string, 1: string} out, in
     */
    public static function descriptions(Account $from, Account $to, string $amountIn): array
    {
        if ($from->ccy !== $to->ccy) {
            $exchange = "EXCHANGE {$from->ccy} TO {$to->ccy} {$amountIn}";

            return [$exchange, $exchange];
        }

        return ['TRANSFER TO '.mb_strtoupper($to->name), 'TRANSFER FROM '.mb_strtoupper($from->name)];
    }

    /**
     * Writes the pair, or rewrites $existing's in place so its ids, and anything linking to
     * them, stay.
     *
     * @param  array{from: Account, to: Account, date: string, amount: string, amount_in: string, description: ?string, status: string}  $input
     * @param  array{0: Transaction, 1: Transaction}|null  $existing  out, in
     * @return array{0: Transaction, 1: Transaction}
     */
    public static function write(array $input, ?array $existing = null): array
    {
        [$outText, $inText] = $input['description'] !== null && trim($input['description']) !== ''
            ? [trim($input['description']), trim($input['description'])]
            : self::descriptions($input['from'], $input['to'], $input['amount_in']);

        $rows = [
            [$input['from'], TransactionType::Withdraw, $input['amount'], $outText],
            [$input['to'], TransactionType::Deposit, $input['amount_in'], $inText],
        ];

        $written = [];

        foreach ($rows as $i => [$account, $type, $amount, $text]) {
            $values = [
                'account_id' => $account->id,
                // Moved, not earned or spent, so filed under nothing.
                'category_id' => null,
                'date' => $input['date'],
                'type' => $type->value,
                'description' => $text,
                'amount' => $amount,
                'ccy' => $account->ccy,
                'status' => $input['status'],
            ];

            $row = $existing[$i] ?? null;

            if ($row === null) {
                $row = Transaction::create($values);
            } else {
                $row->update($values);
            }

            $written[] = $row;
        }

        // Merged into each bag, not replacing it: a one_off or other key the row had stays.
        foreach ([[$written[0], $written[1]], [$written[1], $written[0]]] as [$row, $other]) {
            $bag = $row->meta?->meta?->getArrayCopy() ?? [];
            $bag['paired_transaction_id'] = $other->id;
            $row->meta()->updateOrCreate(['id' => $row->meta?->id], ['meta' => $bag]);
        }

        return [$written[0], $written[1]];
    }

    /**
     * The transfer $row is half of, as out and in, or null when it is not one.
     *
     * @return array{0: Transaction, 1: Transaction}|null
     */
    public static function of(Transaction $row): ?array
    {
        $row->loadMissing(['meta', 'account']);
        $pairedId = $row->meta?->meta['paired_transaction_id'] ?? null;
        $partner = $pairedId === null ? null : Transaction::with(['meta', 'account'])->find($pairedId);

        if (! self::isHalf($row, $partner)) {
            return null;
        }

        return $row->type === TransactionType::Withdraw->value ? [$row, $partner] : [$partner, $row];
    }
}
