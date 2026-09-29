<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Support\RecurringPayments;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * A recurring transaction writes a pending row on each day it falls due, and nothing
 * twice. What is pinned here is the calendar -- the 31st in February, a pause, an end --
 * and that each row goes through TransactionData's guards rather than around them.
 */
class RecurringTransactionTest extends TestCase
{
    use BuildsACard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
        Carbon::setTestNow('2026-03-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_records_what_fell_due_on_save_as_pending_and_nothing_after_today(): void
    {
        $this->post('/recurring', $this->body(['start_date' => '2026-01-10']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', 'Recurring [Rent] saved, 3 pending transactions recorded');

        $rows = Transaction::orderBy('date')->get();

        $this->assertSame(['2026-01-10', '2026-02-10', '2026-03-10'], $rows->pluck('date')->all());
        $this->assertSame(['pending'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame('withdraw', $rows->first()->type);
        $this->assertSame('1200.0000', $rows->first()->amount);

        $this->assertSame('2026-04-10', RecurringTransaction::firstOrFail()->nextDate());
    }

    public function test_a_second_run_writes_nothing_already_written(): void
    {
        $this->post('/recurring', $this->body(['start_date' => '2026-03-01']))->assertSessionHasNoErrors();

        $result = RecurringPayments::recordDue(today());

        $this->assertSame(0, $result['recorded']);
        $this->assertSame(1, Transaction::count());
    }

    public function test_the_31st_falls_on_the_last_day_of_a_short_month_and_returns(): void
    {
        $this->post('/recurring', $this->body(['start_date' => '2026-01-31']))->assertSessionHasNoErrors();

        Carbon::setTestNow('2026-05-01');
        RecurringPayments::recordDue(today());

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'],
            Transaction::orderBy('date')->pluck('date')->all()
        );
    }

    public function test_yearly_repeats_on_the_first_dates_day_and_month(): void
    {
        $this->post('/recurring', $this->body(['start_date' => '2024-02-29', 'frequency' => 'yearly']))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['2024-02-29', '2025-02-28', '2026-02-28'],
            Transaction::orderBy('date')->pluck('date')->all()
        );
        $this->assertSame('2027-02-28', RecurringTransaction::firstOrFail()->nextDate());
    }

    public function test_nothing_is_recorded_after_the_end_date(): void
    {
        $this->post('/recurring', $this->body(['start_date' => '2026-01-10', 'end_date' => '2026-02-20']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Transaction::count());
        $this->assertNull(RecurringTransaction::firstOrFail()->nextDate());
    }

    public function test_an_end_before_the_first_date_is_refused(): void
    {
        $this->post('/recurring', $this->body(['start_date' => '2026-03-10', 'end_date' => '2026-03-01']))
            ->assertSessionHasErrors('end_date');
    }

    public function test_a_paused_rule_records_nothing_and_skips_what_fell_due_when_resumed(): void
    {
        $this->post('/recurring', $this->body(['start_date' => '2026-04-10', 'active' => false]))
            ->assertSessionHasNoErrors();
        $rule = RecurringTransaction::firstOrFail();

        Carbon::setTestNow('2026-06-15');
        RecurringPayments::recordDue(today());
        $this->assertSame(0, Transaction::count());

        $this->put("/recurring/{$rule->id}", $this->body(['start_date' => '2026-04-10']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Transaction::count());
        $this->assertSame('2026-07-10', $rule->fresh()->nextDate());
    }

    public function test_a_card_charge_is_filed_into_its_statement_period(): void
    {
        $this->post('/recurring', $this->body([
            'account_id' => $this->card->id,
            'type' => 'charge',
            'category_id' => $this->category,
            'start_date' => '2026-03-01',
        ]))->assertSessionHasNoErrors();

        $charge = Transaction::with('meta')->firstOrFail();

        $this->assertSame('pending', $charge->status);
        $this->assertSame('2026-04-09', $charge->meta->meta['due_date']);
    }

    public function test_a_foreign_currency_charge_needs_the_card_amount_on_save(): void
    {
        $this->post('/recurring', $this->body([
            'account_id' => $this->card->id,
            'type' => 'charge',
            'category_id' => $this->category,
            'ccy' => 'USD',
        ]))->assertSessionHasErrors('card_amount');

        $this->post('/recurring', $this->body([
            'account_id' => $this->card->id,
            'type' => 'charge',
            'category_id' => $this->category,
            'ccy' => 'USD',
            'card_amount' => '120.50',
            'start_date' => '2026-03-01',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('120.5000', Transaction::with('meta')->firstOrFail()->meta->meta['card_amount']);
    }

    public function test_a_charge_needs_a_category(): void
    {
        $this->post('/recurring', $this->body([
            'account_id' => $this->card->id,
            'type' => 'charge',
            'category_id' => null,
        ]))->assertSessionHasErrors('category_id');
    }

    public function test_a_type_that_cannot_repeat_or_does_not_suit_the_account_is_refused(): void
    {
        $this->post('/recurring', $this->body(['type' => 'buy']))->assertSessionHasErrors('type');
        $this->post('/recurring', $this->body(['type' => 'charge', 'category_id' => $this->category]))
            ->assertSessionHasErrors('type');

        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->post('/recurring', $this->body(['account_id' => $broker->id]))->assertSessionHasErrors('type');

        $this->assertSame(0, RecurringTransaction::count());
    }

    public function test_a_charge_into_a_settled_statement_is_held_back_and_reported(): void
    {
        $this->post('/recurring', $this->body([
            'account_id' => $this->card->id,
            'type' => 'charge',
            'category_id' => $this->category,
            'start_date' => '2026-04-01',
        ]))->assertSessionHasNoErrors();

        // Settle the period 1 April falls in, then let the day arrive.
        $this->charge('2026-03-30', '10');
        Carbon::setTestNow('2026-05-01');
        $this->post("/accounts/{$this->card->id}/settle", ['due_date' => '2026-05-10', 'owed' => '10.0000'])
            ->assertSessionHasNoErrors();

        $result = RecurringPayments::recordDue(today());

        $this->assertSame(0, $result['recorded']);
        $this->assertStringContainsString('[Rent] was not recorded for 2026-04-01', $result['refusals'][0]);
        $this->assertSame('2026-04-01', RecurringTransaction::firstOrFail()->nextDate());
    }

    public function test_the_command_records_what_is_due(): void
    {
        RecurringTransaction::create($this->body(['start_date' => '2026-03-15']));

        $this->artisan('recurring:record')->expectsOutput('1 recorded.')->assertSuccessful();

        $this->assertSame(1, Transaction::count());
    }

    public function test_the_run_button_records_what_is_due_and_nothing_twice(): void
    {
        RecurringTransaction::create($this->body(['start_date' => '2026-02-15']));

        $this->post('/recurring/run')->assertSessionHas('message', '2 pending transactions recorded');
        $this->post('/recurring/run')->assertSessionHas('message', 'Nothing due to record');

        $this->assertSame(2, Transaction::count());
    }

    public function test_deleting_a_rule_keeps_what_it_wrote(): void
    {
        $this->post('/recurring', $this->body(['start_date' => '2026-03-01']))->assertSessionHasNoErrors();

        $this->delete('/recurring/'.RecurringTransaction::firstOrFail()->id)->assertSessionHasNoErrors();

        $this->assertSame(0, RecurringTransaction::count());
        $this->assertSame(1, Transaction::count());
    }

    public function test_a_category_a_rule_files_under_cannot_be_deleted(): void
    {
        $category = Category::create(['name' => 'Rent']);
        RecurringTransaction::create($this->body(['category_id' => $category->id, 'start_date' => '2027-01-01']));

        $this->delete("/categories/{$category->id}")
            ->assertSessionHas('message', fn (string $message) => str_contains($message, 'recurring'));

        $this->assertNotNull($category->fresh());
    }

    public function test_the_page_offers_only_the_types_that_repeat(): void
    {
        Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        RecurringTransaction::create($this->body(['start_date' => '2026-04-10']));

        $this->get('/recurring')->assertInertia(fn (Assert $page) => $page
            ->component('recurring')
            ->where('typeOptions', ['cash' => ['withdraw', 'deposit'], 'card' => ['charge', 'payment']])
            ->where('options.accounts', fn ($accounts) => collect($accounts)->pluck('type')->unique()->sort()->values()->all() === ['card', 'cash'])
            ->where('formEmpty.start_date', '2026-03-15')
            ->where('nextDates', fn ($dates) => array_values((array) $dates->all()) === ['2026-04-10'])
        );
    }

    private function body(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->bank->id,
            'category_id' => null,
            'type' => 'withdraw',
            'description' => 'Rent',
            'amount' => '1200',
            'ccy' => 'HKD',
            'card_amount' => null,
            'frequency' => 'monthly',
            'start_date' => '2026-01-10',
            'end_date' => null,
            'active' => true,
        ], $overrides);
    }
}
