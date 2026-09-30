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
    public function test_a_cards_typical_charges_are_paid_on_its_own_due_dates(): void
    {
        // 2400 on the card last June, paid off, and 120 this month: 210 a month of charges.
        $this->charge('2025-06-01', '2400.0000');
        $this->payment('2025-07-01', '2400.0000', '2025-07-10');
        $this->charge('2026-01-05', '120.0000');

        $section = Forecast::for(today(), 3)->projection()[0];
        $points = collect($section['points'])->keyBy('date');

        $this->assertSame('210.0000', $section['typical_basis']['card']);
        // Charges from tomorrow to the 24th are on this month's statement, due 9 February (the
        // closing day itself rolls to the next): nothing leaves the bank for them before then,
        // and four days' worth leaves on it.
        $this->assertSame('0.0000', $points['2026-02-08']['allowance']);
        $this->assertSame('27.6164', $points[self::PERIOD]['allowance']);
        // The next statement's charges, the 25th on, wait for its own due date in March.
        $this->assertSame('27.6164', $points['2026-03-01']['allowance']);
    }

    public function test_the_projection_has_its_months_its_events_and_what_they_leave(): void
    {
        $this->row('deposit', '2025-05-01', '2200');
        $this->row('withdraw', '2025-06-01', '1200');
        $this->rule(['type' => 'withdraw', 'amount' => '100', 'start_date' => '2026-02-01']);

        $section = Forecast::for(today(), 3)->projection()[0];

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04'], array_column($section['months'], 'month'));
        $this->assertSame('100.0000', $section['months'][1]['out']);
        $this->assertSame('1900.0000', $section['months'][1]['end_known']);

        $this->assertSame('2026-02-01', $section['events'][0]['date']);
        $this->assertSame('-100.0000', $section['events'][0]['base']);
        $this->assertSame('1900.0000', $section['events'][0]['balance']);

        // After today, so not today's 2000 but where the rule leaves it by April.
        $this->assertSame(['amount' => '1700.0000', 'date' => '2026-04-01'], $section['lowest_ahead']['known']);
        // 2000 at the year's average of 100 a month, earning nothing.
        $this->assertSame('20.0', $section['runway_months']);
    }

    public function test_typical_income_is_the_years_average_less_what_the_rules_bring(): void
    {
        // 1000 from setUp and 2600 more in the year: 300 a month, 100 of it a salary rule.
        $this->row('deposit', '2025-06-01', '2600');
        $this->rule(['type' => 'deposit', 'amount' => '100', 'start_date' => '2026-02-01']);

        $section = Forecast::for(today(), 3)->projection()[0];
        $points = collect($section['points'])->keyBy('date');

        $this->assertSame('200.0000', $section['typical_income']);
        $this->assertSame(['average' => '300.0000', 'recurring' => '100.0000', 'dividends' => '0.0000'], $section['typical_income_basis']);
        // Earned from tomorrow, and on the typical line only.
        $this->assertSame('0.0000', $points['2026-01-20']['earned']);
        $this->assertTrue((float) $points['2026-02-20']['typical'] > (float) $points['2026-02-20']['known']);
    }

    public function test_a_holdings_dividends_are_expected_a_year_on_scaled_to_what_is_held_now(): void
    {
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $buy = fn (string $date, string $quantity) => $this->post('/transactions', [
            'account_id' => $broker->id, 'date' => $date, 'type' => 'buy', 'description' => 'Buy',
            'ccy' => 'HKD', 'status' => 'posted',
            'meta_data' => ['symbol' => '0005.HK', 'quantity' => $quantity, 'unit_price' => '10', 'no_cash' => true],
        ])->assertSessionHasNoErrors();
        $dividend = fn (string $date, string $amount) => $this->post('/transactions', [
            'account_id' => $this->bank->id, 'date' => $date, 'type' => 'dividend', 'description' => 'Dividend',
            'amount' => $amount, 'ccy' => 'HKD', 'status' => 'posted',
            'meta_data' => ['symbol' => '0005.HK', 'brokerage_account_id' => $broker->id],
        ])->assertSessionHasNoErrors();

        $buy('2025-01-01', '100');
        $dividend('2025-02-01', '40');
        $dividend('2025-03-10', '50');
        $buy('2025-06-01', '100');
        $dividend('2025-09-10', '100');
        // This year's February payment, declared and entered ahead of last year's date.
        $dividend('2026-01-25', '45');

        $section = Forecast::for(today(), 12)->projection()[0];
        $expected = collect($section['events'])->where('kind', 'expected dividend')->values();

        // March's paid on 100 shares and 200 are held now, so twice it; February is already on
        // file, so not expected again.
        $this->assertSame(['2026-03-10', '2026-09-10'], $expected->pluck('date')->all());
        $this->assertSame(['100.0000', '100.0000'], $expected->pluck('base')->all());
        $this->assertSame('200.0000', $section['expected_dividends']);

        // An estimate beside the known closing, never in it: last year's 190 and the declared
        // 45 are known.
        $account = $section['accounts'][0];
        $this->assertSame('1235.0000', $account['closing']);
        $this->assertSame('200.0000', $account['dividends']);
        $this->assertSame('1435.0000', $account['closing_expected']);

        // Taken out of typical income, which would otherwise count them a second time: the
        // year's 235, the declared row included as the cash flow month counts it.
        $this->assertSame('19.5833', $section['typical_income_basis']['dividends']);
    }

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
