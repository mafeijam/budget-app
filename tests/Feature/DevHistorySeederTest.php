<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\AccountBalance;
use App\Support\CardStatement;
use App\Support\CashFlow;
use Carbon\Carbon;
use Database\Seeders\DevCategorySeeder;
use Database\Seeders\DevHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * The year of history the cash flow chart is looked at with. What matters is that it is
 * a year the app would have recorded itself: statements settled as settle() settles
 * them, nothing dated after today, and a bank that never goes below zero.
 */
class DevHistorySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-29 12:00:00');

        $this->seed(DevCategorySeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_refuses_a_database_that_does_not_look_like_a_test_one(): void
    {
        Config::set('database.connections.mysql.database', 'budget');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run DevHistorySeeder');

        $this->seed(DevHistorySeeder::class);
    }

    public function test_it_refuses_to_run_before_the_categories_exist(): void
    {
        Category::query()->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs the dev categories');

        $this->seed(DevHistorySeeder::class);
    }

    public function test_every_month_of_the_chart_has_income_and_spending(): void
    {
        $this->seed(DevHistorySeeder::class);

        $months = collect(CashFlow::lastMonths(today()))->firstWhere('ccy', 'HKD')['months'];

        $this->assertCount(12, $months);

        foreach ($months as $month) {
            $this->assertGreaterThan(0, (float) $month['income'], "{$month['month']} has no income");
            $this->assertGreaterThan(0, (float) $month['spending'], "{$month['month']} has no spending");
        }

        // The trip month spends more than it earns, so the net line crosses zero.
        $this->assertTrue(collect($months)->contains(fn ($month) => str_starts_with($month['net'], '-')));
    }

    public function test_every_statement_already_due_is_settled_and_the_newest_is_owing(): void
    {
        $this->seed(DevHistorySeeder::class);

        $statements = CardStatement::forAccount(Account::where('name', DevHistorySeeder::CARD)->firstOrFail());

        foreach ($statements as $statement) {
            $this->assertSame(
                $statement->dueDate < today()->toDateString(),
                $statement->isSettled(),
                "The statement due {$statement->dueDate} is in the wrong state."
            );
        }

        $this->assertFalse($statements->last()->isSettled());
    }

    public function test_nothing_is_dated_after_today_and_the_bank_stays_positive(): void
    {
        $this->seed(DevHistorySeeder::class);

        $this->assertSame(0, Transaction::where('date', '>', today()->toDateString())->count());

        $bank = Account::where('name', DevHistorySeeder::BANK)->firstOrFail();

        $this->assertStringStartsNotWith('-', AccountBalance::forAccounts(collect([$bank]))[$bank->id]);
    }

    public function test_re_running_replaces_the_history_rather_than_doubling_it(): void
    {
        $this->seed(DevHistorySeeder::class);
        $count = Transaction::count();

        $this->seed(DevHistorySeeder::class);

        $this->assertSame($count, Transaction::count());
        $this->assertSame(2, Account::whereIn('name', [DevHistorySeeder::BANK, DevHistorySeeder::CARD])->count());
    }
}
