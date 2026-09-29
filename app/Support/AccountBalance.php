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
        $balances = self::balanceable($accounts);

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
     * The accounts that hold a balance, each at zero, which is the shape both readers here
     * fill in: an account with no rows is 0.0000 rather than absent, and a brokerage is
     * left out entirely.
     *
     * @param  Collection<int, Account|AccountData>  $accounts
     * @return array<int, string>
     */
    private static function balanceable(Collection $accounts): array
    {
        $balances = [];

        foreach ($accounts as $account) {
            if (self::typeOf($account)->hasBalance()) {
                $balances[$account->id] = '0.0000';
            }
        }

        return $balances;
    }

    /**
     * Every account's balance on each of a run of days, in one query for all of them.
     *
     * $days must be in ascending order: the accumulation walks one cursor per account
     * forward through the months rather than re-reading them for every day.
     *
     * @param  Collection<int, Account|AccountData>  $accounts
     * @param  list<string>  $days
     * @return array<string, array<int, string>> day => account id => balance
     */
    public static function seriesFor(Collection $accounts, array $days): array
    {
        if ($days === []) {
            return [];
        }

        $balances = self::balanceable($accounts);

        if ($balances === []) {
            return [];
        }

        $signs = self::signCases();
        $types = array_keys($signs);

        if ($types === []) {
            return [];
        }

        $cases = implode(' ', $signs);
        $counting = implode("', '", TransactionStatus::countingTowardBalance());
        $placeholders = implode(', ', array_fill(0, count($balances), '?'));

        $latest = (string) max($days);

        // Grouped by month rather than filtered per day, because every period end this is
        // asked about is a month end and the run of days ends at today, so a month's
        // movements accumulated up to here answer for every day asked of it. That is one
        // query instead of one per day: a monthly chart against a ten-year ledger is 123
        // days, and asking the same aggregate 123 times was most of a five-second page.
        $rows = DB::select(
            "SELECT t.account_id,
                    DATE_FORMAT(t.date, '%Y-%m') AS ym,
                    SUM(CASE {$cases} ELSE 0 END) AS movement
               FROM transactions t
               LEFT JOIN meta m ON m.model_id = t.id AND m.model_type = ?
              WHERE t.account_id IN ({$placeholders})
                AND t.status IN ('{$counting}')
                AND t.type IN ('".implode("', '", $types)."')
                AND t.date <= ?
           GROUP BY t.account_id, ym",
            [Transaction::class, ...array_keys($balances), $latest]
        );

        /** @var array<string, array<string, string>> $byAccount  account id => month => movement */
        $byAccount = [];

        foreach ($rows as $row) {
            $byAccount[(int) $row->account_id][(string) $row->ym] = (string) $row->movement;
        }

        // Walked once per account rather than once per day per account. Asking "everything
        // up to this month" for each day in turn is a quadratic read of the same buckets --
        // 117 days against 120 months is a hundred thousand additions -- and since the days
        // come in order, one cursor per account answers all of them.
        $sorted = [];

        foreach ($balances as $id => $opening) {
            $months = $byAccount[$id] ?? [];
            ksort($months);

            $sorted[$id] = ['months' => array_keys($months), 'movements' => array_values($months), 'at' => 0, 'total' => BigDecimal::of($opening)];
        }

        $series = [];

        foreach ($days as $day) {
            $month = substr($day, 0, 7);
            $line = [];

            foreach ($sorted as $id => &$account) {
                while ($account['at'] < count($account['months']) && $account['months'][$account['at']] <= $month) {
                    $account['total'] = $account['total']->plus($account['movements'][$account['at']]);
                    $account['at']++;
                }

                $line[$id] = self::decimal($account['total']->toScale(4)->toString());
            }

            unset($account);

            $series[$day] = $line;
        }

        return $series;
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
