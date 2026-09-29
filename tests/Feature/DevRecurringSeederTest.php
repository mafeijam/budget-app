<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Support\RecurringPayments;
use Carbon\Carbon;
use Database\Seeders\DevAccountSeeder;
use Database\Seeders\DevCategorySeeder;
use Database\Seeders\DevRecurringSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * The seeder writes rules straight to the model, so what is asserted is that each one is
 * a rule the form would accept and that none is due the moment it lands.
 */
class DevRecurringSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-29 12:00:00');

        $this->seed(DevCategorySeeder::class);
        $this->seed(DevAccountSeeder::class);
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
        $this->expectExceptionMessage('Refusing to run DevRecurringSeeder');

        $this->seed(DevRecurringSeeder::class);
    }

    public function test_it_refuses_to_run_before_the_accounts_exist(): void
    {
        Account::query()->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs the dev accounts');

        $this->seed(DevRecurringSeeder::class);
    }

    public function test_it_refuses_to_run_before_the_categories_exist(): void
    {
        Category::query()->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs the dev categories');

        $this->seed(DevRecurringSeeder::class);
    }

    public function test_nothing_is_due_when_it_lands(): void
    {
        $this->seed(DevRecurringSeeder::class);

        $this->assertSame(0, RecurringPayments::recordDue(today())['recorded']);
        $this->assertTrue(RecurringTransaction::all()->every(fn ($rule) => $rule->nextDate() > '2026-09-29'));
    }

    public function test_the_salary_on_the_31st_lands_on_the_last_day_of_a_short_month(): void
    {
        $this->seed(DevRecurringSeeder::class);

        $salary = RecurringTransaction::where('description', 'Salary')->firstOrFail();

        $this->assertSame('2026-10-31', $salary->start_date);
        $this->assertSame(['2026-10-31', '2026-11-30'], $salary->dueThrough(Carbon::parse('2026-12-01')));
    }

    public function test_the_yearly_rule_ends_and_one_rule_is_paused(): void
    {
        $this->seed(DevRecurringSeeder::class);

        $yearly = RecurringTransaction::where('frequency', 'yearly')->firstOrFail();
        $this->assertSame('2027-03-15', $yearly->start_date);
        $this->assertSame('2029-03-15', $yearly->end_date);

        $this->assertSame(['Savings top-up'], RecurringTransaction::where('active', false)->pluck('description')->all());
    }

    public function test_every_rule_is_one_the_form_would_accept(): void
    {
        $this->seed(DevRecurringSeeder::class);

        foreach (RecurringTransaction::all() as $rule) {
            $body = $rule->only([
                'account_id', 'category_id', 'type', 'description', 'amount', 'ccy', 'card_amount',
                'frequency', 'start_date', 'end_date', 'active',
            ]);

            $this->put("/recurring/{$rule->id}", $body)->assertSessionHasNoErrors();
        }
    }

    public function test_re_running_replaces_the_set_rather_than_doubling_it(): void
    {
        $this->seed(DevRecurringSeeder::class);
        $count = RecurringTransaction::count();

        $this->seed(DevRecurringSeeder::class);

        $this->assertSame($count, RecurringTransaction::count());
        $this->assertSame(6, $count);
    }
}
