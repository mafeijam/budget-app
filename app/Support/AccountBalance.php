<?php

namespace App\Support;

use App\DTO\AccountData;
use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Each account's position, in one query for a page of them. A card owing 45.25 reads
 * -45.25: the opposite sign to CardStatement::owed(), which is a period's debt.
 *
 * Signs and counted statuses come from the enums, so a new case is counted rather than
 * silently left out of every balance.
 */
class AccountBalance
{
    /**
     * '0.0000' for an account with no rows, so a blank means a brokerage rather than
     * nothing held.
     *
     * @param  Collection<int, Account|AccountData>  $accounts
     * @param  string|null  $onOrBefore  a day to read the balance as of, or every row
     * @return array<int, string> account id => a decimal at the amount column's scale
     */
    public static function forAccounts(Collection $accounts, ?string $onOrBefore = null): array
    {
        $zero = '0.0000';

        $balances = [];

        foreach ($accounts as $account) {
            if (self::typeOf($account)->hasBalance()) {
                $balances[$account->id] = $zero;
            }
        }

        if ($balances === []) {
            return [];
        }

        $signs = self::signCases();
        $types = array_keys($signs);

        if ($types === []) {
            return $balances;
        }

        $cases = implode(' ', $signs);
        $counting = implode("', '", TransactionStatus::countingTowardBalance());
        $placeholders = implode(', ', array_fill(0, count($balances), '?'));

        $rows = DB::select(
            // LEFT JOIN: a charge on a card with no statement day has no bag, and an
            // inner join would drop it from what the card owes.
            "SELECT t.account_id,
                    SUM(CASE {$cases} ELSE 0 END) AS balance
               FROM transactions t
               LEFT JOIN meta m ON m.model_id = t.id AND m.model_type = ?
              WHERE t.account_id IN ({$placeholders})
                AND t.status IN ('{$counting}')
                AND t.type IN ('".implode("', '", $types)."')
                ".($onOrBefore === null ? '' : 'AND t.date <= ?').'
           GROUP BY t.account_id',
            // Transaction::class: the bag read is the transaction's, not the account's.
            [Transaction::class, ...array_keys($balances), ...($onOrBefore === null ? [] : [$onOrBefore])]
        );

        foreach ($rows as $row) {
            $balances[(int) $row->account_id] = self::decimal($row->balance);
        }

        return $balances;
    }

    /**
     * Keyed by type, so the same set drives both the CASE and the WHERE.
     *
     * @return array<string, string> type value => `WHEN 'type' THEN ±figure`
     */
    private static function signCases(): array
    {
        $cases = [];

        foreach (TransactionType::cases() as $type) {
            foreach ($type->accountTypes() as $accountType) {
                if (! $accountType->hasBalance()) {
                    continue;
                }

                $sign = $type->movesBalanceOn($accountType);

                if ($sign === 0) {
                    continue;
                }

                // CardStatement's expression, so a card total and a statement cannot
                // disagree about a cross-currency charge.
                $figure = $type === TransactionType::Charge
                    ? CardStatement::cardCurrencySql()
                    : 't.amount';

                $cases[$type->value] = sprintf(
                    "WHEN t.type = '%s' THEN %s%s",
                    $type->value,
                    $sign < 0 ? '-' : '',
                    $figure
                );
            }
        }

        return $cases;
    }

    /** Either shape: AccountData::collect() swaps the paginator's models for DTOs. */
    private static function typeOf(Account|AccountData $account): AccountType
    {
        return $account->type instanceof AccountType
            ? $account->type
            : AccountType::from($account->type);
    }

    /** Rows that all cancel come back as an integer 0, which would read "0" beside "120.0000". */
    private static function decimal(int|string $value): string
    {
        return BigDecimal::of((string) $value)->toScale(4)->toString();
    }
}
