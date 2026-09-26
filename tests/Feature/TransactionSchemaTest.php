<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the transactions table shape for the three account types.
 *
 * The table is shared by cash, credit card and stock trading rows, so several
 * columns are only meaningful for some of them. These tests exist because that
 * makes the nullability of each column load-bearing: a card payment and a stock
 * trade have no category, a trade's amount is derived rather than supplied, and
 * only a charge carries a due date. Getting any of those wrong does not fail
 * loudly -- it either rejects a valid row or silently admits an invalid one.
 */
class TransactionSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every column is aliased explicitly. information_schema reports its
     * column names upper-case, and whether the driver hands them back upper- or
     * lower-case depends on PDO::ATTR_CASE, so the aliases pin it down.
     */
    private function indexes(): array
    {
        $rows = DB::select(
            "SELECT index_name AS idx_name, column_name AS col_name, seq_in_index AS col_seq
               FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'transactions'
              ORDER BY index_name, seq_in_index"
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->idx_name][] = $row->col_name;
        }

        return $indexes;
    }

    private function columns(): array
    {
        $rows = DB::select(
            "SELECT column_name AS col_name, data_type AS col_type,
                    is_nullable AS col_nullable, column_default AS col_default,
                    numeric_precision AS col_precision, numeric_scale AS col_scale
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'transactions'"
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->col_name] = $row;
        }

        return $columns;
    }

    private function column(string $name): object
    {
        $columns = $this->columns();

        $this->assertArrayHasKey(
            $name,
            $columns,
            "transactions.{$name} is missing; found: ".implode(', ', array_keys($columns))
        );

        return $columns[$name];
    }

    public function test_category_id_is_optional(): void
    {
        // A card payment settles a statement rather than buying anything, and a
        // stock trade is not an expense, so neither can be categorised. The
        // original column was NOT NULL, which made both impossible to record.
        $this->assertSame('YES', $this->column('category_id')->col_nullable);
    }

    public function test_status_exists_and_defaults_to_posted(): void
    {
        $status = $this->column('status');

        $this->assertSame('varchar', $status->col_type);
        $this->assertSame('NO', $status->col_nullable);
        $this->assertSame('posted', $status->col_default);
    }

    public function test_fx_rate_is_an_optional_high_precision_decimal(): void
    {
        // Converts `amount`, which is denominated in `ccy`, into the owning
        // account's currency. NULL means "already in the account's currency",
        // i.e. a rate of 1. 16.8 comfortably holds a rate such as 7.8495.
        $rate = $this->column('fx_rate');

        $this->assertSame('decimal', $rate->col_type);
        $this->assertSame('YES', $rate->col_nullable);
        $this->assertSame(16, (int) $rate->col_precision);
        $this->assertSame(8, (int) $rate->col_scale);
    }

    public function test_due_date_is_an_optional_plain_date(): void
    {
        // Only a card charge rolls up into a statement period, so this is NULL
        // for every cash, payment, trade and dividend row. It is a plain date
        // and not a timestamp: a statement falls due on a calendar day, and
        // storing a time would imply a settlement deadline that does not exist.
        $due = $this->column('due_date');

        $this->assertSame('date', $due->col_type);
        $this->assertSame('YES', $due->col_nullable);
    }

    public function test_amount_stays_not_null(): void
    {
        // Regression guard. A stock trade's amount is derived from
        // quantity x unit price rather than supplied by the client, so it is
        // nullable in the DTO -- but it must be written back before the row is
        // persisted. If this ever becomes NULL, SUM(amount) silently drops
        // trades from a balance instead of failing.
        $this->assertSame('NO', $this->column('amount')->col_nullable);
    }

    public function test_account_and_date_are_indexed_together(): void
    {
        // The balance query is always scoped to one account and ordered by
        // date.
        $this->assertContains(
            ['account_id', 'date'],
            array_values($this->indexes()),
            'Expected a composite index on (account_id, date); found: '
                .json_encode($this->indexes())
        );
    }

    public function test_account_and_due_date_are_indexed_together(): void
    {
        // How much a card statement owes is a GROUP BY due_date scoped to one
        // account:
        //
        //   SELECT due_date, SUM(charge) - SUM(payment) FROM transactions
        //    WHERE account_id = ? GROUP BY due_date
        //
        // Leading with account_id keeps it scoped; trailing due_date lets MySQL
        // take the groups off the index in order instead of sorting them.
        //
        // due_date is the period key, which is why there is no statements table
        // to join for this.
        $this->assertContains(
            ['account_id', 'due_date'],
            array_values($this->indexes()),
            'Expected a composite index on (account_id, due_date); found: '
                .json_encode($this->indexes())
        );
    }

    public function test_category_id_is_indexed(): void
    {
        // Required by the category_id foreign key, and independently the lookup
        // behind "everything filed under this category" -- the question a
        // category delete has to answer before it may claim the category is
        // unused.
        $indexed = array_merge(...array_values($this->indexes()));

        $this->assertContains('category_id', $indexed, 'Found: ' . json_encode($this->indexes()));
    }
}
