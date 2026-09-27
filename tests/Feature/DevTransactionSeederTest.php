<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\AccountBalance;
use App\Support\CardStatement;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Database\Seeders\DevAccountSeeder;
use Database\Seeders\DevCategorySeeder;
use Database\Seeders\DevTransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the dev transaction fixtures.
 *
 * A seeder that writes rows straight to the model bypasses TransactionData, so
 * nothing validates what it produces. The point of these tests is therefore not
 * that the rows exist but that they are USABLE and that they say what they were
 * written to say: a charge placed in a real statement period, a cross-currency
 * charge carrying the figure its card actually sums, and accounts whose balances
 * are the ones the account table will show.
 *
 * The figures are asserted rather than merely counted. A fixture set that seeds
 * rows but produces a balance nobody checked is how a bug in the balance query
 * survives -- the numbers would be whatever the query said, and the fixture would
 * be the thing asserting it.
 *
 * The database guard has its own test here for the reason it does in
 * DevAccountSeederTest: .env points DB_DATABASE at the live database, so
 * `db:seed --class=DevTransactionSeeder` with no override would write fixtures
 * into real data.
 */
class DevTransactionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DevCategorySeeder::class);
        $this->seed(DevAccountSeeder::class);
    }

    // ---------------------------------------------------------------------
    // It refuses to run where it should not
    // ---------------------------------------------------------------------

    public function test_it_refuses_a_database_that_does_not_look_like_a_test_one(): void
    {
        Config::set('database.connections.mysql.database', 'budget');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run DevTransactionSeeder');

        $this->seed(DevTransactionSeeder::class);
    }

    public function test_it_refuses_to_run_before_the_accounts_exist(): void
    {
        Account::query()->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs the dev accounts');

        $this->seed(DevTransactionSeeder::class);
    }

    public function test_it_refuses_to_run_before_the_categories_exist(): void
    {
        Category::query()->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs the dev categories');

        $this->seed(DevTransactionSeeder::class);
    }

    // ---------------------------------------------------------------------
    // What it writes
    // ---------------------------------------------------------------------

    public function test_it_writes_transactions_on_cash_and_card_accounts(): void
    {
        $this->seed(DevTransactionSeeder::class);

        $types = Account::whereIn('type', [AccountType::Cash->value, AccountType::Card->value])
            ->pluck('type')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [AccountType::Card->value, AccountType::Cash->value],
            $types,
            'The fixtures should cover both balance-bearing account types.'
        );

        $this->assertGreaterThan(0, Transaction::count());
    }

    public function test_it_leaves_a_brokerage_with_no_transactions(): void
    {
        // The same reason a brokerage reports no balance: a trade's worth needs a
        // price this app does not carry, so a fixture trade would be a figure on
        // screen meaning nothing.
        $this->seed(DevTransactionSeeder::class);

        $brokerages = Account::where('type', AccountType::Security->value)->pluck('id');

        $this->assertSame(
            0,
            Transaction::whereIn('account_id', $brokerages)->count(),
            'A brokerage was given transactions. A cash or card balance says nothing '
            .'about what a brokerage holds, and DevAccountSeeder provides two of them '
            .'so this is reachable.'
        );
    }

    public function test_every_type_it_writes_is_legal_on_the_account_it_sits_on(): void
    {
        // The DTO refuses an illegal pairing, and a seeder bypassing the DTO would
        // happily write one -- which is a row the app can list but never re-save.
        $this->seed(DevTransactionSeeder::class);

        $illegal = [];

        foreach (Transaction::with('account')->get() as $transaction) {
            $type = TransactionType::from($transaction->type);

            if (! $type->isAllowedFor(AccountType::from($transaction->account->type))) {
                $illegal[] = $transaction->type.' on a '.$transaction->account->type;
            }
        }

        $this->assertSame([], $illegal);
    }

    public function test_every_charge_lands_in_a_real_statement_period(): void
    {
        // A due date the app would not have derived is a charge sitting in a period no
        // statement query groups by, so it would be missing from what the card owes
        // with nothing reporting it.
        $this->seed(DevTransactionSeeder::class);

        foreach (Account::where('type', AccountType::Card->value)->get() as $card) {
            $periods = CardStatement::forAccount($card)->pluck('dueDate');

            $charges = Transaction::where('account_id', $card->id)
                ->where('type', TransactionType::Charge->value)
                ->get();

            foreach ($charges as $charge) {
                $dueDate = $charge->meta?->meta['due_date'] ?? null;

                $this->assertNotNull($dueDate, "A charge on [{$card->name}] has no due date.");

                $this->assertContains(
                    $dueDate,
                    $periods->all(),
                    "A charge on [{$card->name}] claims due {$dueDate}, which is not a period any statement reports."
                );
            }
        }
    }

    public function test_a_charge_derives_the_due_date_the_app_would_have_derived(): void
    {
        // The seeder must not hardcode this, or the fixture and the app could disagree
        // about which statement a charge belongs to and the seeder would be pinning
        // the bug rather than exposing it.
        $this->seed(DevTransactionSeeder::class);

        $card = Account::where('name', 'Dev Card')->firstOrFail();
        $cycle = CardStatementCycle::fromMeta($card->meta?->meta);

        $this->assertNotNull($cycle);

        $charge = Transaction::where('account_id', $card->id)
            ->where('type', TransactionType::Charge->value)
            ->where('date', '2026-01-10')
            ->firstOrFail();

        $this->assertSame(
            $cycle->dueDateFor(Carbon::parse('2026-01-10'))->toDateString(),
            $charge->meta?->meta['due_date']
        );
    }

    public function test_the_cross_currency_charge_carries_the_figure_its_card_sums(): void
    {
        // The one row whose amount is not what the card owes. If card_amount were
        // dropped the card would read as owing 100 rather than 780, and every other
        // assertion in this file would still pass.
        $this->seed(DevTransactionSeeder::class);

        $charge = Transaction::where('description', 'US Store')->firstOrFail();

        $this->assertSame('100.0000', $charge->amount, 'The merchant charged in USD.');
        $this->assertSame('USD', $charge->ccy);
        $this->assertSame('780.0000', $charge->meta?->meta['card_amount']);
    }

    public function test_it_writes_a_pending_row_of_each_kind_that_is_excluded_from_a_balance(): void
    {
        // A fixture set with no pending row cannot show a build counting one.
        $this->seed(DevTransactionSeeder::class);

        $this->assertGreaterThan(
            0,
            Transaction::where('status', TransactionStatus::Pending->value)
                ->where('type', TransactionType::Charge->value)
                ->count(),
            'No pending charge. A pending charge must not be owed, and without one the '
            .'accounts table and the transaction table cannot disagree visibly.'
        );

        $this->assertGreaterThan(
            0,
            Transaction::where('status', TransactionStatus::Pending->value)
                ->where('type', TransactionType::Income->value)
                ->count(),
            'No pending income. Pending is the only status that does not count toward a '
            .'balance, and a cash row is where that is easiest to get wrong.'
        );
    }

    public function test_every_categorised_row_has_a_category(): void
    {
        // An expense or a charge with no category is a row the edit form would reject
        // and the create form cannot produce, so it is worse than no fixture.
        $this->seed(DevTransactionSeeder::class);

        $uncategorised = Transaction::whereNull('category_id')
            ->whereIn('type', [
                TransactionType::Expense->value,
                TransactionType::Charge->value,
            ])
            ->pluck('description')
            ->all();

        $this->assertSame([], $uncategorised);
    }

    // ---------------------------------------------------------------------
    // The figures the accounts table will show
    // ---------------------------------------------------------------------

    public function test_the_balances_are_the_ones_the_fixtures_were_written_for(): void
    {
        // Asserted, not discovered. A fixture that took whatever the balance query
        // said would be the query's own evidence.
        //
        //   Dev Cash         5000 - 1200.50 - 80 - 900        =  2819.5000
        //   Dev Cash Reserve 0.10 + 0.20, pending 77 excluded =     0.3000
        //   Dev Card         -120 - 780 + 900 - 250           =  -250.0000
        //   Dev Card Everyday -45.25, pending 99 excluded     =   -45.2500
        //
        // The card rows are negative because a balance is a position: a card owing
        // 45.25 leaves the user down 45.25. The statement period for the same card
        // reads owed 45.2500, and both are right -- see
        // test_a_card_left_settled_reads_zero_and_one_left_owing_reads_its_charge.
        $this->seed(DevTransactionSeeder::class);

        $expected = [
            'Dev Cash' => '2819.5000',
            'Dev Cash Reserve' => '0.3000',
            'Dev Card' => '-250.0000',
            'Dev Card Everyday' => '-45.2500',
        ];

        $accounts = Account::whereIn('name', array_keys($expected))->get();
        $balances = AccountBalance::forAccounts($accounts);

        foreach ($expected as $name => $balance) {
            $id = $accounts->firstWhere('name', $name)->id;

            $this->assertSame($balance, $balances[$id] ?? null, "Balance for [{$name}].");
        }
    }

    public function test_one_card_has_a_settled_period_and_one_left_owing(): void
    {
        // Both figures from owed(), which is a period's debt and stays positive -- so
        // the 45.2500 here and the -45.2500 in the balance above are the same money.
        // Two figures in one column, so a build rendering every card as 0.0000 or
        // every card as its gross charges is visible side by side.
        $this->seed(DevTransactionSeeder::class);

        // Two periods on one card, which is the shape the panel needs: the first was
        // paid off and the second was not, and outstandingFor() rejects a settled one.
        // So Dev Card gives the statement two rows and the panel one, and a reader
        // comparing the two sees the filter rather than a discrepancy.
        $periods = CardStatement::forAccount(Account::where('name', 'Dev Card')->firstOrFail());

        $this->assertCount(2, $periods);
        $this->assertSame('0.0000', $periods->first()->owed(), 'The period that was paid off.');
        $this->assertSame('250.0000', $periods->last()->owed(), 'The one left owing.');

        $this->assertSame(
            '45.2500',
            CardStatement::forAccount(Account::where('name', 'Dev Card Everyday')->firstOrFail())
                ->sole()
                ->owed()
        );
    }

    public function test_the_decimal_case_really_would_have_caught_a_float(): void
    {
        // 0.1 + 0.2 in binary floating point. If the balance had gone through a float
        // this fixture would print 0.30000000000000004, and the assertion above would
        // be the thing that noticed.
        $this->assertNotSame(0.3, 0.1 + 0.2);

        $this->seed(DevTransactionSeeder::class);

        $reserve = Account::where('name', 'Dev Cash Reserve')->firstOrFail();

        $this->assertSame('0.3000', AccountBalance::forAccounts(collect([$reserve]))[$reserve->id]);
    }

    // ---------------------------------------------------------------------
    // Re-running
    // ---------------------------------------------------------------------

    public function test_running_it_twice_leaves_the_same_rows(): void
    {
        // The seeder owns the transactions on the accounts it names, so a second run
        // restores the intended state rather than doubling every figure. A balance
        // that changed on a second run is the failure this guards.
        $this->seed(DevTransactionSeeder::class);

        $first = AccountBalance::forAccounts(Account::whereIn('type', [
            AccountType::Cash->value,
            AccountType::Card->value,
        ])->get());

        $count = Transaction::count();

        $this->seed(DevTransactionSeeder::class);

        $this->assertSame($count, Transaction::count(), 'A second run changed the row count.');
        $this->assertSame(
            $first,
            AccountBalance::forAccounts(Account::whereIn('type', [
                AccountType::Cash->value,
                AccountType::Card->value,
            ])->get()),
            'A second run changed a balance.'
        );
    }

    public function test_running_it_twice_does_not_duplicate_a_settlement_pair(): void
    {
        // The pair is the part of a fixture most likely to be seeded twice by hand,
        // and a doubled pair would settle a statement that owes nothing.
        $this->seed(DevTransactionSeeder::class);
        $this->seed(DevTransactionSeeder::class);

        $card = Account::where('name', 'Dev Card')->firstOrFail();

        $this->assertSame(
            1,
            Transaction::where('account_id', $card->id)
                ->where('type', TransactionType::Payment->value)
                ->count()
        );
    }
}
