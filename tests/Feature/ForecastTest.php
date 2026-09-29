<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Price;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Support\Forecast;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * Each cash account's balance from today, from what is known to be coming.
 */
class ForecastTest extends TestCase
{
    use BuildsACard, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-01-20 12:00', 'Asia/Hong_Kong'));
        $this->setUpCard();

        $this->row('deposit', '2026-01-02', '1000');
    }

    public function test_it_starts_from_todays_balance_and_follows_rows_dated_ahead(): void
    {
        $this->row('withdraw', '2026-02-03', '300');

        $account = $this->account();

        $this->assertSame('1000.0000', $account['opening']);
        $this->assertSame('700.0000', $account['closing']);
        $this->assertSame(['amount' => '700.0000', 'date' => '2026-02-03'], $account['lowest']);
    }

    public function test_a_pending_row_is_counted_from_today(): void
    {
        $this->row('withdraw', '2026-01-10', '100', 'pending');

        $this->assertSame(['amount' => '900.0000', 'date' => '2026-01-20'], $this->account()['lowest']);
    }

    public function test_a_recurring_rule_adds_each_occurrence_it_has_still_to_write(): void
    {
        $this->rule(['type' => 'withdraw', 'amount' => '200', 'start_date' => '2026-02-01']);

        // Three occurrences in three months: 1 February, March and April.
        $this->assertSame('400.0000', $this->account()['closing']);
    }

    public function test_a_card_statement_leaves_its_bank_on_the_due_date(): void
    {
        $this->charge('2026-01-05', '120.0000');
        $this->charge('2026-01-06', '30.0000', 'pending');

        $upcoming = collect(Forecast::for(today(), 3)->upcoming())->firstWhere('kind', 'statement');

        // The pending charge is on the bill too.
        $this->assertSame('2026-02-09', $upcoming['date']);
        $this->assertSame('-150.0000', $upcoming['amount']);
        $this->assertSame('850.0000', $this->account()['closing']);
    }

    public function test_an_overdue_statement_is_paid_today_and_a_bankless_card_is_named(): void
    {
        $this->chargeOn($this->card, '2025-11-05', '50.0000');
        $this->card->meta()->update(['meta' => ['term_days' => 15, 'statement_day' => 25]]);

        $forecast = Forecast::for(today(), 3);

        $this->assertSame([], collect($forecast->upcoming())->where('kind', 'statement')->all());
        $this->assertStringContainsString('Card [Card] owes 50.0000 HKD', $forecast->warnings()[0]);

        $this->card->meta()->update(['meta' => ['term_days' => 15, 'statement_day' => 25, 'settlement_account_id' => $this->bank->id]]);

        $this->assertSame('2026-01-20', collect(Forecast::for(today(), 3)->upcoming())->firstWhere('kind', 'statement')['date']);
    }

    public function test_typical_spending_is_the_years_average_less_what_the_rules_cover(): void
    {
        // 2400 spent across the last twelve months: 200 a month. A 50-a-month rule covers part.
        $this->row('withdraw', '2025-06-01', '2400');
        $this->rule(['type' => 'withdraw', 'amount' => '50', 'start_date' => '2026-03-01']);

        $section = Forecast::for(today(), 3)->projection()[0];

        $this->assertSame('150.0000', $section['typical_monthly']);

        // The known line never carries the estimate.
        $this->assertSame($section['points'][0]['known'], $section['points'][0]['typical']);
        $this->assertTrue((float) $section['points'][30]['typical'] < (float) $section['points'][30]['known']);
    }

    public function test_the_month_outlook_adds_what_is_still_to_come_to_the_month_so_far(): void
    {
        // So far: 1000 in on the 2nd. Still to come: a pending 100 out this month, a rule's
        // 40 on the 25th. A pending row from last month is not this month's.
        $this->row('withdraw', '2026-01-10', '100', 'pending');
        $this->row('withdraw', '2025-12-10', '999', 'pending');
        $this->rule(['type' => 'withdraw', 'amount' => '40', 'start_date' => '2026-01-25']);

        $outlook = Forecast::for(today(), 3)->monthOutlook()[0];

        $this->assertSame('2026-01', $outlook['month']);
        $this->assertSame('1000.0000', $outlook['so_far']['net']);
        $this->assertSame('140.0000', $outlook['to_come']['spending']);
        $this->assertSame('860.0000', $outlook['likely_known']);
        $this->assertSame(11, $outlook['days_left']);
    }

    public function test_every_currency_is_merged_in_hkd_or_one_is_shown_in_its_own(): void
    {
        $usd = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        Transaction::create([
            'account_id' => $usd->id, 'category_id' => null, 'date' => '2026-01-02', 'type' => 'deposit',
            'description' => 'Salary', 'amount' => '100', 'ccy' => 'USD', 'status' => 'posted',
        ]);
        Price::create(['symbol' => 'USDHKD=X', 'date' => '2026-01-19', 'close' => '7.8', 'ccy' => 'HKD', 'source' => 'manual']);

        $all = Forecast::for(today(), 3)->projection();

        // 1000 HKD and 100 USD at 7.8.
        $this->assertCount(1, $all);
        $this->assertSame('HKD', $all[0]['ccy']);
        $this->assertSame('1780.0000', $all[0]['points'][0]['known']);
        $this->assertSame('100.0000', collect($all[0]['accounts'])->firstWhere('ccy', 'USD')['native']['opening']);

        $only = Forecast::for(today(), 3)->projection('USD');

        $this->assertSame('USD', $only[0]['ccy']);
        $this->assertSame('100.0000', $only[0]['points'][0]['known']);

        $this->get('/forecast?ccy=USD')->assertInertia(fn (Assert $page) => $page
            ->where('ccy', 'USD')
            ->where('currencies', ['HKD', 'USD'])
        );
        $this->get('/forecast?ccy=EUR')->assertInertia(fn (Assert $page) => $page->where('ccy', null));
    }

    public function test_the_page_takes_an_offered_horizon_and_ignores_any_other(): void
    {
        $this->get('/forecast?months=6')->assertInertia(fn (Assert $page) => $page
            ->component('forecast')
            ->where('months', 6)
            ->where('horizons', [3, 6, 12])
            ->where('projection.0.points', fn ($points) => count($points) === 182)
        );

        $this->get('/forecast?months=2')->assertInertia(fn (Assert $page) => $page->where('months', 3));
    }

    /** @return array<string, mixed> */
    private function account(): array
    {
        return Forecast::for(today(), 3)->projection()[0]['accounts'][0];
    }

    private function row(string $type, string $date, string $amount, string $status = 'posted'): void
    {
        Transaction::create([
            'account_id' => $this->bank->id,
            'category_id' => null,
            'date' => $date,
            'type' => $type,
            'description' => ucfirst($type),
            'amount' => $amount,
            'ccy' => 'HKD',
            'status' => $status,
        ]);
    }

    private function rule(array $overrides): void
    {
        RecurringTransaction::create([
            'account_id' => $this->bank->id,
            'category_id' => null,
            'description' => 'Rule',
            'ccy' => 'HKD',
            'frequency' => 'monthly',
            'active' => true,
            ...$overrides,
        ]);
    }
}
