<?php

namespace App\Support\Legacy;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Reads the pre-Laravel `budget` database, and only reads it.
 *
 * Every method goes through the `budget` connection rather than a raw PDO handle so
 * that a stray write would be a visible mistake in review rather than a habit. Nothing
 * here caches: the migration reads each table once and holds it, because the whole
 * dataset is a few thousand rows and a second query per row is what turns a migration
 * into an afternoon.
 */
class BudgetSource
{
    /** @return list<array<string, mixed>> */
    public function accounts(): array
    {
        return $this->rows('SELECT id, name FROM accounts ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    public function cards(): array
    {
        return $this->rows('SELECT id, name FROM cards ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return $this->rows('SELECT id, name FROM categories ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    public function cardTransactions(): array
    {
        return $this->rows(
            'SELECT id, card_id, description, amount, cat_id, date, paid, split
               FROM card_transactions ORDER BY id'
        );
    }

    /**
     * The running `balance` the old schema stored on each row, for the reconciliation:
     * the last row per account is what the bank itself reported, so comparing it against
     * a balance summed from the rows says whether the old ledger adds up.
     *
     * @return list<array<string, mixed>>
     */
    public function cashTransactions(): array
    {
        return $this->rows(
            'SELECT id, acct_id, date, description, type, amount, balance FROM cash_transactions ORDER BY id'
        );
    }

    /**
     * group_num is read because the migration skips the second lot: it is the account
     * holder's own grouping of the 2021-06 batch, not a position to replay.
     *
     * @return list<array<string, mixed>>
     */
    public function stockHoldings(): array
    {
        return $this->rows(
            'SELECT id, code, qty, cost, date, sold, group_num FROM stock_holding ORDER BY date, id'
        );
    }

    /** @return list<array<string, mixed>> */
    public function recurringPayments(): array
    {
        return $this->rows(
            'SELECT id, description, day, paid, amount, card_id, cat_id FROM recurring_payment ORDER BY id'
        );
    }

    /**
     * The date the old database was last written to, so the report can say how stale the
     * source is rather than implying the migration covers everything up to today.
     */
    public function latestActivity(): ?string
    {
        $rows = $this->rows(
            'SELECT GREATEST(
                (SELECT MAX(date) FROM cash_transactions),
                (SELECT MAX(date) FROM card_transactions),
                (SELECT MAX(date) FROM stock_holding)
             ) AS latest'
        );

        $latest = $rows[0]['latest'] ?? null;

        return $latest === null ? null : (string) $latest;
    }

    /**
     * The stdClass rows select() returns, cast to arrays: every caller here indexes its
     * rows as arrays, which is the shape the rest of the migration is written in.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        try {
            return array_map(
                fn (object $row) => (array) $row,
                DB::connection('budget')->select($sql)
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Could not read the old budget database: {$e->getMessage()}\n\n"
                .'It is reached through the `budget` connection, so check BUDGET_DB_DATABASE '
                .'and the BUDGET_DB_* credentials in .env.',
                0,
                $e
            );
        }
    }
}
