<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the transactions table shape for the three account types.
 *
 * The table is shared by cash, credit card and stock trading rows, so several
 * columns are only meaningful for some of them. These tests exist because that
 * makes the nullability of each column load-bearing: a category on a payment or
 * a trade is optional rather than impossible, a trade's amount is derived
 * rather than supplied, and only a charge carries a due date. Getting any of
 * those wrong does not fail loudly -- it either rejects a valid row or silently
 * admits an invalid one.
 */
class TransactionSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every column is aliased explicitly. information_schema reports its
     * column names upper-case, and whether the driver hands them back upper- or
     * lower-case depends on PDO::ATTR_CASE, so the aliases pin it down.
     */
    /**
     * Foreign key constraints, as [columns => referenced table].
     *
     * information_schema.referential_constraints carries the delete rule but
     * not the columns; key_column_usage carries the columns and the referenced
     * table but not the rule. Both are needed to say anything useful, and the
     * pair is joined on constraint_name.
     */
    private function foreignKeys(): array
    {
        // constraint_schema, not table_schema: referential_constraints names the
        // column that way, and the two are equal for a same-schema reference but
        // only one of them is a real column on this table.
        $constraints = DB::select(
            "SELECT constraint_name AS fk_name, delete_rule AS fk_delete
               FROM information_schema.referential_constraints
              WHERE constraint_schema = DATABASE() AND table_name = 'transactions'"
        );

        $columns = DB::select(
            "SELECT constraint_name AS fk_name, column_name AS col_name,
                    referenced_table_name AS ref_table
               FROM information_schema.key_column_usage
              WHERE table_schema = DATABASE() AND table_name = 'transactions'
                AND referenced_table_name IS NOT NULL"
        );

        $byName = [];
        foreach ($columns as $row) {
            $byName[$row->fk_name]['columns'][] = $row->col_name;
            $byName[$row->fk_name]['table'] = $row->ref_table;
        }

        $keys = [];
        foreach ($constraints as $row) {
            $keys[] = [
                'columns' => $byName[$row->fk_name]['columns'] ?? [],
                'table' => $byName[$row->fk_name]['table'] ?? null,
                'on_delete' => $row->fk_delete,
            ];
        }

        return $keys;
    }

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
        // stock trade is not an expense, so neither is obliged to be
        // categorised. The original column was NOT NULL, which made both
        // impossible to record. Nullable, not prohibited: a payment may still be
        // labelled, and that is a rule in TransactionData rather than a
        // constraint here.
        $this->assertSame('YES', $this->column('category_id')->col_nullable);
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

    public function test_transactions_are_bound_to_the_accounts_and_categories_they_name(): void
    {
        // The `exists:` rules in the DTO are the only thing currently rejecting
        // an orphan id, and they only run on the way in. A row written by
        // anything else -- a seeder, a tinker session, a future import -- would
        // sail past them, and the settlement query would attribute a statement
        // to an account that does not exist.
        $this->assertContains(
            ['columns' => ['account_id'], 'table' => 'accounts', 'on_delete' => 'RESTRICT'],
            $this->foreignKeys(),
            'Found: '.json_encode($this->foreignKeys())
        );

        $this->assertContains(
            ['columns' => ['category_id'], 'table' => 'categories', 'on_delete' => 'RESTRICT'],
            $this->foreignKeys(),
            'Found: '.json_encode($this->foreignKeys())
        );
    }

    public function test_deleting_an_account_with_transactions_is_refused(): void
    {
        // Restrict, not cascade. A cascade here would delete a user's financial
        // history because they tidied up an account, so this asserts the delete
        // is stopped and the transaction survives.
        $account = Account::create(['name' => 'Spender', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $transaction = Transaction::create([
            'account_id' => $account->id,
            'category_id' => null,
            'amount' => 10,
            'type' => 'withdraw',
            'description' => 'Lunch',
            'ccy' => 'HKD',
            'date' => '2026-09-26',
            'status' => 'posted',
        ]);

        // Assert the database refuses, not merely that Laravel raises: the
        // constraint is the thing under test.
        $refused = false;
        try {
            DB::table('accounts')->where('id', $account->id)->delete();
        } catch (QueryException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'The database allowed an account to be deleted out from under a transaction.');
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_deleting_a_category_with_transactions_is_refused(): void
    {
        // Same reasoning, and the case that matters most: a category is a label,
        // so losing one should never take the transactions filed under it with
        // it.
        $account = Account::create(['name' => 'Spender', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $category = Category::create(['name' => 'Food']);

        $transaction = Transaction::create([
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => 10,
            'type' => 'withdraw',
            'description' => 'Lunch',
            'ccy' => 'HKD',
            'date' => '2026-09-26',
            'status' => 'posted',
        ]);

        $refused = false;
        try {
            DB::table('categories')->where('id', $category->id)->delete();
        } catch (QueryException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'The database allowed a category to be deleted along with its transactions.');
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    }

    public function test_a_transaction_naming_a_missing_account_is_refused(): void
    {
        // The write-side half of the guarantee: the DTO's exists: rules are
        // bypassable by any other writer, and this is the database saying no on
        // their behalf.
        $account = Account::create(['name' => 'Spender', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $this->expectException(QueryException::class);

        DB::table('transactions')->insert([
            'account_id' => $account->id + 9999,
            'category_id' => null,
            'amount' => 10,
            'type' => 'withdraw',
            'description' => 'Orphan',
            'ccy' => 'HKD',
            'date' => '2026-09-26',
            'status' => 'posted',
        ]);
    }
}
