<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Price;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Support\CardStatementCycle;
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

    public function test_typical_spending_is_the_years_median_month_less_what_the_rules_cover(): void
    {
        // 200 in each of the last twelve months, and a 5000 holiday in June: the median month
        // is still 200, where the mean would be 616.67. A 50-a-month rule covers part.
        foreach (range(0, 11) as $back) {
            $this->row('withdraw', today()->startOfMonth()->subMonthsNoOverflow($back)->addDays(9)->toDateString(), '200');
        }

        $this->row('withdraw', '2025-06-15', '5000');
        $this->rule(['type' => 'withdraw', 'amount' => '50', 'start_date' => '2026-03-01']);

        $section = Forecast::for(today(), 3)->projection()[0];

        $this->assertSame('150.0000', $section['typical_monthly']);

        // The known line never carries the estimate.
        $this->assertSame($section['points'][0]['known'], $section['points'][0]['typical']);
        $this->assertTrue((float) $section['points'][30]['typical'] < (float) $section['points'][30]['known']);
    }

    public function test_what_the_year_spent_beyond_an_ordinary_month_is_spent_as_one_offs(): void
    {
        // The median test's year: 200 a month, a 5000 holiday in June, a 50 rule. The ordinary
        // month is 150 and the rule 50, against an average of 600 over 2025's twelve months.
        foreach (range(0, 11) as $back) {
            $this->row('withdraw', today()->startOfMonth()->subMonthsNoOverflow($back)->addDays(9)->toDateString(), '200');
        }

        $this->row('withdraw', '2025-06-15', '5000');
        $this->rule(['type' => 'withdraw', 'amount' => '50', 'start_date' => '2026-03-01']);

        $forecast = Forecast::for(today(), 3);
        $section = $forecast->projection()[0];
        $points = collect($section['points'])->keyBy('date');

        // The rest of the 600: the holiday, which a median sets at nothing.
        $this->assertSame('150.0000', $section['typical_monthly']);
        $this->assertSame('400.0000', $section['typical_basis']['one_offs']);
        $this->assertSame('600.0000', $section['typical_basis']['average']);

        // Spread from tomorrow, apart from the ordinary allowance, and off the typical line.
        $this->assertSame('0.0000', $points['2026-01-20']['one_offs']);
        $day = $points['2026-03-20'];
        $this->assertSame(
            round((float) $day['known'] - (float) $day['allowance'] - (float) $day['one_offs'] + (float) $day['earned'], 4),
            (float) $day['typical'],
        );
        $this->assertGreaterThan(0, (float) $day['one_offs']);

        // In a month's typical spending, and its own figure of it.
        $february = collect($section['months'])->firstWhere('month', '2026-02');
        $this->assertGreaterThan(0, (float) $february['one_offs']);
        $this->assertGreaterThan((float) $february['one_offs'], (float) $february['typical']);

        // And in the rest of this month, so the outlook's month end is the chart's: the cash
        // 150 and the 400 for 11 of January's 31 days.
        $this->assertSame('195.1613', $forecast->monthOutlook()[0]['typical_rest']);
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

    public function test_the_outlook_leaves_a_card_charge_to_the_statement_that_carries_it(): void
    {
        // A card charge made this month is paid on the due date of the statement it falls
        // in, which is next month on these terms. Counting it here as well charged January
        // for it twice, and put it in a month the money never leaves the bank in.
        $this->rule(['account_id' => $this->card->id, 'type' => 'charge', 'amount' => '70', 'start_date' => '2026-01-25']);
        $this->charge('2026-01-05', '55', 'pending');
        $this->rule(['type' => 'withdraw', 'amount' => '40', 'start_date' => '2026-01-25']);

        $outlook = Forecast::for(today(), 3)->monthOutlook()[0];

        $this->assertSame('40.0000', $outlook['to_come']['spending']);
        $this->assertSame('0.0000', $outlook['to_come']['income']);
    }

    public function test_the_card_charge_the_outlook_leaves_out_is_still_in_the_forecast(): void
    {
        $this->rule(['account_id' => $this->card->id, 'type' => 'charge', 'amount' => '70', 'start_date' => '2026-01-25']);

        $events = collect(Forecast::for(today(), 3)->projection()[0]['events']);

        // On the due date of the statement it falls in, not on the day it was charged. The
        // outlook's leaving it out is not losing it, only moving it to the month it is paid.
        $due = CardStatementCycle::fromMeta($this->card->meta?->meta)
            ->dueDateFor(Carbon::parse('2026-01-25'))->toDateString();

        $this->assertGreaterThan('2026-01-31', $due);
        $this->assertSame('-70.0000', $events->firstWhere('date', $due)['amount']);
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
        // 210 charged in each of the last twelve months and a 2400 one-off last June: the
        // median month is 210, the one-off no larger a month than any other.
        foreach (range(0, 11) as $back) {
            $this->charge(today()->startOfMonth()->subMonthsNoOverflow($back)->addDays(4)->toDateString(), '210.0000');
        }

        $this->charge('2025-06-01', '2400.0000');

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

    public function test_typical_income_is_the_median_month_less_what_the_rules_bring(): void
    {
        // The window is the twelve complete months, 2025 itself: a steady 250 a month of
        // deposits no rule accounts for from February, one month carrying a 2600 lump on top of
        // it. setUp's 1000 is this month's, which is never one of the twelve.
        $this->rule(['type' => 'deposit', 'amount' => '100', 'start_date' => '2026-02-01']);
        $this->row('deposit', '2025-06-01', '2600');

        foreach (range(2, 12) as $month) {
            $this->row('deposit', sprintf('2025-%02d-15', $month), '250');
        }

        $section = Forecast::for(today(), 3)->projection()[0];
        $points = collect($section['points'])->keyBy('date');

        // Ten months at 250 with the rule's 100 off, so 150 an ordinary month: the rule has
        // written nothing yet, so it is taken off every month at its amount. The mean is 446,
        // because the 2600 lands in a single month of the twelve.
        $this->assertSame('150.0000', $section['typical_income']);
        $this->assertSame('150.0000', $section['typical_income_basis']['median']);
        $this->assertSame(['average' => '445.8333', 'median' => '150.0000', 'recurring' => '100.0000', 'dividends' => '0.0000'], $section['typical_income_basis']);
        // Earned from tomorrow, and on the typical line only.
        $this->assertSame('0.0000', $points['2026-01-20']['earned']);
        $this->assertTrue((float) $points['2026-02-20']['typical'] > (float) $points['2026-02-20']['known']);
    }

    public function test_this_month_is_not_one_of_the_twelve_a_typical_figure_is_taken_over(): void
    {
        // 300 a month in the first half of 2025 and 100 in the second, so 200 is the median of
        // the twelve complete months. Taken over the twelve to this month instead, January
        // 2025 drops out and this month's three weeks of nothing come in -- a part-month that
        // is a whole month's empty slot, which put the median at 100.
        foreach (range(1, 12) as $month) {
            $this->row('withdraw', sprintf('2025-%02d-10', $month), $month <= 6 ? '300' : '100');
        }

        $this->assertSame('200.0000', Forecast::for(today(), 3)->projection()[0]['typical_monthly']);
    }

    public function test_a_raise_inside_the_year_is_the_rules_and_not_income_lost(): void
    {
        // The salary rule pays 120 now. Its own rows paid 100 until August, 120 from
        // September, and twice that in December. 30 a month of other deposits besides.
        $this->rule(['type' => 'deposit', 'amount' => '120', 'description' => 'SALARY', 'start_date' => '2025-01-01']);

        foreach (range(1, 12) as $month) {
            $salary = match (true) {
                $month === 12 => '240',
                $month >= 9 => '120',
                default => '100',
            };

            $this->row('deposit', sprintf('2025-%02d-01', $month), $salary, description: 'SALARY');
            $this->row('deposit', sprintf('2025-%02d-15', $month), '30', description: 'Interest');
        }

        $section = Forecast::for(today(), 3)->projection()[0];

        // 30 in every month. Today's 120 off each of them read the eight months at the old
        // salary as 10, and the median with them; the December double is the rule's too, and
        // is placed on its own date rather than read as a month of income.
        $this->assertSame('30.0000', $section['typical_income']);
    }

    public function test_a_month_that_pays_a_lump_does_not_set_the_typical_month(): void
    {
        // A 2600 in a single month against eleven that pay nothing at all (setUp's 1000 is
        // this month's, and not one of the twelve): there is no ordinary month to find, so the
        // median is the rule's 100 taken off an empty month and the figure floors at nothing.
        $this->row('deposit', '2025-06-01', '2600');
        $this->rule(['type' => 'deposit', 'amount' => '100', 'start_date' => '2026-02-01']);

        $section = Forecast::for(today(), 3)->projection()[0];

        $this->assertSame('0.0000', $section['typical_income']);
        // The mean is still reported, so the reader can see what the month would be on it.
        $this->assertSame('216.6667', $section['typical_income_basis']['average']);
    }

    public function test_a_bonus_is_placed_on_the_date_it_was_paid_rather_than_spread(): void
    {
        // A steady 250 a month, a 100 a month rule, and a bonus paid last June. The bonus
        // must not reach the monthly figure, and must land on its own date a year on.
        $this->rule(['type' => 'deposit', 'amount' => '100', 'start_date' => '2026-02-01']);
        $this->row('deposit', '2025-06-01', '2000', description: 'BONUS');

        foreach (range(2, 12) as $month) {
            $this->row('deposit', sprintf('2025-%02d-15', $month), '250');
        }

        $section = Forecast::for(today(), 12)->projection()[0];
        $months = collect($section['months'])->keyBy('month');

        // Unmoved by the bonus: June is the one month that does not pay the steady 250 alone,
        // and the median of the twelve is still an ordinary month.
        $this->assertSame('150.0000', $section['typical_income']);
        $this->assertSame('2000.0000', $section['expected_bonuses']);

        // On the first of June a year on, in June's typical income and its own column of it,
        // and on no other month.
        $this->assertSame('2000.0000', $months['2026-06']['bonuses']);
        $this->assertSame('0.0000', $months['2026-07']['bonuses']);
        $this->assertGreaterThan(
            (float) $months['2026-07']['typical_in'],
            (float) $months['2026-06']['typical_in'],
        );

        // An estimate, on its own kind, linked to the row it came from.
        $bonus = collect($section['events'])->firstWhere('kind', 'expected bonus');

        $this->assertNotNull($bonus);
        $this->assertSame('2026-06-01', $bonus['date']);
        $this->assertSame('2000.0000', $bonus['base']);
        $this->assertSame('Bonus, as paid 2025-06-01', $bonus['description']);
        $this->assertTrue($bonus['estimate']);
    }

    public function test_a_month_paid_twice_over_places_the_extra_on_the_same_date_a_year_on(): void
    {
        // 100 a month all year, and one month at 250: a second month's pay, and the part
        // above the median is what a year on is expected to bring.
        $this->rule(['type' => 'deposit', 'amount' => '100', 'start_date' => '2026-02-01']);

        foreach ($this->salaryMonths([6]) as $month) {
            $this->row('deposit', $month, '100', description: 'SALARY');
        }

        $this->row('deposit', '2025-06-01', '250', description: 'SALARY');

        $section = Forecast::for(today(), 12)->projection()[0];
        $months = collect($section['months'])->keyBy('month');

        $this->assertSame('150.0000', $section['expected_double_pay']);
        $this->assertSame('150.0000', $months['2026-06']['double_pay']);
        $this->assertSame('0.0000', $months['2026-07']['double_pay']);

        // On the typical line only, as every other estimate is. The known figure for June is
        // the rule's own 100 -- the 250 was paid last June, so the extra is nowhere in it.
        $this->assertSame('100.0000', $months['2026-06']['in']);
        $this->assertGreaterThan(
            (float) $months['2026-05']['typical_in'],
            (float) $months['2026-06']['typical_in'],
        );

        $double = collect($section['events'])->firstWhere('kind', 'expected double pay');

        $this->assertNotNull($double);
        $this->assertSame('2026-06-01', $double['date']);
        $this->assertSame('150.0000', $double['base']);
        $this->assertSame('Double pay, as paid 2025-06-01', $double['description']);
        $this->assertTrue($double['estimate']);
    }

    public function test_a_raise_is_not_read_as_a_second_months_pay(): void
    {
        // 100 a month all year, and one month at 140: a raise, not two payments' worth, and
        // projecting it would put a month's salary on the typical line for a year.
        $this->rule(['type' => 'deposit', 'amount' => '100', 'start_date' => '2026-02-01']);

        foreach ($this->salaryMonths([6]) as $month) {
            $this->row('deposit', $month, '100', description: 'SALARY');
        }

        $this->row('deposit', '2025-06-01', '140', description: 'SALARY');

        $section = Forecast::for(today(), 12)->projection()[0];
        $months = collect($section['months'])->keyBy('month');

        $this->assertSame('0.0000', $section['expected_double_pay']);
        $this->assertSame('0.0000', $months['2026-06']['double_pay']);
        $this->assertNull(collect($section['events'])->firstWhere('kind', 'expected double pay'));
    }

    public function test_two_payments_in_one_month_read_as_one_double(): void
    {
        // The doubled month paid as two rows rather than one, and the extra is the same: the
        // month is added up before it is measured, not the first row of it.
        $this->rule(['type' => 'deposit', 'amount' => '100', 'start_date' => '2026-02-01']);

        foreach ($this->salaryMonths([6]) as $month) {
            $this->row('deposit', $month, '100', description: 'SALARY');
        }

        $this->row('deposit', '2025-06-01', '150', description: 'SALARY');
        $this->row('deposit', '2025-06-01', '100', description: 'SALARY');

        $section = Forecast::for(today(), 12)->projection()[0];

        $this->assertSame('150.0000', $section['expected_double_pay']);
    }

    /**
     * One salary date a month through the last year, skipping the named months so a test can
     * give one of them a different figure without two rows landing in the same month.
     *
     * @param  list<int>  $except
     * @return list<string>
     */
    private function salaryMonths(array $except): array
    {
        return collect(range(2, 12))
            ->reject(fn (int $month) => in_array($month, $except, true))
            ->map(fn (int $month) => sprintf('2025-%02d-01', $month))
            ->all();
    }

    public function test_a_refund_is_not_projected_because_nothing_says_it_recurs(): void
    {
        $this->row('deposit', '2025-06-01', '8975', description: 'TAX REFUND');

        $section = Forecast::for(today(), 12)->projection()[0];

        // A bonus has a name and a yearly cadence; a refund in the window might be the one
        // that comes every June or the one that came this June, and projecting it either way
        // is a guess with a date on it.
        $this->assertSame('0.0000', $section['expected_bonuses']);
        $this->assertNull(collect($section['events'])->firstWhere('kind', 'expected bonus'));
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

        // Taken out of typical income, which would otherwise count them a second time: the 190
        // of the twelve complete months. The declared 45 is this month's, and this month is
        // never one of the twelve.
        $this->assertSame('15.8333', $section['typical_income_basis']['dividends']);
    }

    public function test_coming_up_lists_an_expected_dividend_as_an_estimate(): void
    {
        $this->heldSymbolPaidLastYear('2025-02-01', '40');

        $upcoming = collect(Forecast::for(today(), 3)->upcoming());
        $dividend = $upcoming->firstWhere('kind', 'expected dividend');

        // Coming money, and inside the panel's window, so it belongs in it. It was left out
        // for as long as this read only the known events, which is what said a fortnight held
        // nothing when a dividend was expected in it.
        $this->assertNotNull($dividend);
        $this->assertSame('2026-02-01', $dividend['date']);
        $this->assertSame('40.0000', $dividend['amount']);
        $this->assertTrue($dividend['estimate'], 'It moves the typical line only, and says so.');
        $this->assertSame('Bank', $dividend['account']);

        // The row it came from, so a click on it finds that payment and not every payment on
        // the same holding.
        $this->assertSame('2025-02-01', $dividend['link']['date']);
    }

    public function test_coming_up_is_in_date_order(): void
    {
        // Nothing gathered the events in date order -- rows, then rules, then statements -- so
        // a panel holding more than one kind of them never was. An estimate merged in makes it
        // visible, since one dated before a bill would otherwise follow it.
        $this->heldSymbolPaidLastYear('2025-02-01', '40');
        $this->rule(['type' => 'withdraw', 'amount' => '20', 'start_date' => '2026-01-25']);
        $this->charge('2026-01-05', '55');

        $upcoming = collect(Forecast::for(today(), 3)->upcoming())
            ->filter(fn (array $event) => $event['date'] <= '2026-02-09')
            ->pluck('date')
            ->all();

        $this->assertSame($upcoming, collect($upcoming)->sort()->values()->all());
        $this->assertContains('2026-01-25', $upcoming);
        $this->assertContains('2026-02-01', $upcoming);
    }

    /** A holding bought into, then a dividend paid on it a year before this month. */
    private function heldSymbolPaidLastYear(string $paid, string $amount): void
    {
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $this->post('/transactions', [
            'account_id' => $broker->id, 'date' => '2025-01-01', 'type' => 'buy', 'description' => 'Buy',
            'ccy' => 'HKD', 'status' => 'posted',
            'meta_data' => ['symbol' => '0005.HK', 'quantity' => '100', 'unit_price' => '10', 'no_cash' => true],
        ])->assertSessionHasNoErrors();

        $this->post('/transactions', [
            'account_id' => $this->bank->id, 'date' => $paid, 'type' => 'dividend', 'description' => 'Dividend',
            'amount' => $amount, 'ccy' => 'HKD', 'status' => 'posted',
            'meta_data' => ['symbol' => '0005.HK', 'brokerage_account_id' => $broker->id],
        ])->assertSessionHasNoErrors();
    }

    private function account(): array
    {
        return Forecast::for(today(), 3)->projection()[0]['accounts'][0];
    }

    private function row(
        string $type,
        string $date,
        string $amount,
        string $status = 'posted',
        ?string $description = null,
    ): void {
        Transaction::create([
            'account_id' => $this->bank->id,
            'category_id' => null,
            'date' => $date,
            'type' => $type,
            'description' => $description ?? ucfirst($type),
            'amount' => $amount,
            'ccy' => 'HKD',
            'status' => $status,
        ]);
    }

    public function test_a_settlement_is_not_listed_as_coming_up(): void
    {
        // The bank half of a card payment is a plain withdraw and nothing about the row says
        // card, so it arrived in Coming up as "Card payment [NAME]" dated ahead -- money that
        // has already moved, and the same money the panel already carries as the statement
        // event. Written the way settle() writes it, both halves and the pairing.
        $this->settled('2026-02-09', '175.0000');

        $upcoming = collect(Forecast::for(today(), 3)->upcoming());

        $this->assertSame([], $upcoming->where('description', 'Card payment [Card]')->all());
        $this->assertSame(
            [],
            $upcoming->where('kind', 'scheduled')->all(),
            'Nothing else was dated ahead, so nothing should be listed.'
        );
    }

    public function test_a_settlement_dated_ahead_is_still_left_out(): void
    {
        // Posted on a day this panel looks at, which is how a settlement lands in Coming up
        // at all. The panel is about what has not happened yet.
        $this->settled('2026-02-09', '175.0000', '2026-02-09');

        $this->assertSame(
            [],
            collect(Forecast::for(today(), 3)->upcoming())
                ->where('description', 'Card payment [Card]')->all()
        );
    }

    public function test_an_ordinary_withdrawal_is_still_listed(): void
    {
        // The exclusion is the settlement and nothing wider: a withdraw that settles no card
        // is ordinary spending and the panel's whole reason to exist.
        $this->row('withdraw', '2026-02-03', '300', 'posted', 'Rent');

        $event = collect(Forecast::for(today(), 3)->upcoming())->firstWhere('description', 'Rent');

        $this->assertSame('2026-02-03', $event['date']);
        $this->assertSame('-300.0000', $event['amount']);
        $this->assertSame('scheduled', $event['kind']);
    }

    public function test_a_listed_row_carries_the_date_a_filter_can_find_it_by(): void
    {
        // A pending row is listed under today whatever its own date, so the date a click
        // filters on has to be the row's own -- filtering on the listed day finds nothing.
        $this->row('withdraw', '2026-01-10', '100', 'pending', 'Coffee');

        $event = collect(Forecast::for(today(), 3)->upcoming())->firstWhere('description', 'Coffee');

        $this->assertSame('2026-01-20', $event['date'], 'Listed under today.');
        $this->assertSame('2026-01-10', $event['link']['date'], 'But linked by the row own date.');
        $this->assertNotNull($event['link']['transaction']);
    }

    /**
     * A card statement settled from the bank, written the way settle() writes it: a payment
     * on the card, a withdraw on the bank, and the two paired in the card row's bag.
     */
    private function settled(string $dueDate, string $amount, string $paidOn = '2026-01-25'): void
    {
        $this->charge('2026-01-05', $amount);

        $payment = Transaction::create([
            'account_id' => $this->card->id,
            'category_id' => null,
            'date' => $paidOn,
            'type' => 'payment',
            'description' => "Statement {$dueDate}",
            'amount' => $amount,
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $transfer = Transaction::create([
            'account_id' => $this->bank->id,
            'category_id' => null,
            'date' => $paidOn,
            'type' => 'withdraw',
            'description' => 'Card payment [Card]',
            'amount' => $amount,
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $payment->meta()->create([
            'meta' => ['due_date' => $dueDate, 'paired_transaction_id' => $transfer->id],
        ]);

        // Both directions, as settle() writes them: the bank row's bag is what pairs it to
        // a card payment, and without it the bank row is an ordinary withdraw.
        $transfer->meta()->create([
            'meta' => ['paired_transaction_id' => $payment->id],
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
