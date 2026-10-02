<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Price;
use App\Support\NetWorth;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What everything held is worth on a day, in HKD, and the snapshots over time.
 */
class NetWorthTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $broker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-15 12:00', 'Asia/Hong_Kong'));

        $this->bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);
    }

    public function test_cash_plus_the_stocks_at_their_last_close(): void
    {
        $this->row($this->bank, 'deposit', '2026-01-02', '10000');
        $this->buy('2026-02-01', '100', '40');
        $this->price('0700.HK', '2026-09-10', '50', 'HKD');

        $card = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $card->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);
        $food = Category::create(['name' => 'Food']);
        $this->row($card, 'charge', '2026-03-01', '300', ['category_id' => $food->id]);

        $now = (new NetWorth)->on('2026-09-15');

        // 10000 in, 4000 out on the buy; 100 shares at 50; 300 owed on the card.
        $this->assertSame('6000.0000', $now['cash']);
        $this->assertSame('-300.0000', $now['cards']);
        $this->assertSame('5000.0000', $now['value']);
        $this->assertSame('4000.0000', $now['cost']);
        $this->assertSame('1000.0000', $now['unrealised']);

        // 6000 + 5000. The 300 owed is reported and is not in this: a debt netted against
        // the cash set aside to pay it is the same bill subtracted twice.
        $this->assertSame('11000.0000', $now['net_worth']);
    }

    public function test_a_snapshot_leaves_out_what_came_after_its_day(): void
    {
        $this->row($this->bank, 'deposit', '2026-01-02', '10000');
        $this->buy('2026-06-01', '100', '40');

        $then = (new NetWorth)->on('2026-05-31');

        $this->assertSame('10000.0000', $then['cash']);
        $this->assertSame('0.0000', $then['value']);
        $this->assertSame([], $then['brokerages']);
    }

    public function test_a_holding_with_no_close_counts_at_cost_and_is_counted(): void
    {
        $this->row($this->bank, 'deposit', '2026-01-02', '10000');
        $this->buy('2026-02-01', '100', '40');

        $now = (new NetWorth)->on('2026-09-15');

        $this->assertSame('4000.0000', $now['value']);
        $this->assertSame(1, $now['unpriced']);
    }

    public function test_another_currency_is_converted_at_the_days_rate_or_left_out(): void
    {
        $usd = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        $this->row($usd, 'deposit', '2026-01-02', '100');

        $this->assertSame('0.0000', (new NetWorth)->on('2026-09-15')['cash']);
        $this->assertSame(['USD'], (new NetWorth)->on('2026-09-15')['unconverted']);

        // The last close on or before the day, so a weekend takes Friday's.
        $this->price('USDHKD=X', '2026-09-11', '7.8', 'HKD');
        $this->price('USDHKD=X', '2026-09-16', '9', 'HKD');

        $now = (new NetWorth)->on('2026-09-15');

        $this->assertSame('780.0000', $now['cash']);
        $this->assertSame([], $now['unconverted']);
    }

    public function test_the_history_is_a_snapshot_at_each_period_end_and_today(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        $history = (new NetWorth)->history(3, today());

        $this->assertSame(['2026-03-31', '2026-06-30', '2026-09-15'], array_column($history, 'date'));
        $this->assertSame(['100.0000', '150.0000', '150.0000'], array_column($history, 'cash'));

        $this->assertSame(['2026-09-15'], array_column((new NetWorth)->history(12, today()), 'date'));
    }

    public function test_a_limited_history_is_the_most_recent_periods(): void
    {
        // A ledger years deep, which is the only thing that can tell a limit from a
        // slice. The two tests above run on a handful of months, where keeping the
        // oldest few and the newest few look identical -- which is how a six-month
        // trend came to draw 2016 off a ten-year ledger without a test noticing.
        $this->row($this->bank, 'deposit', '2019-01-10', '10');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        $this->assertSame(
            ['2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31', '2026-08-31', '2026-09-15'],
            array_column((new NetWorth)->history(1, today(), 5), 'date')
        );

        // Oldest first, always: a caller plotting the series reads it in that order.
        $dates = array_column((new NetWorth)->history(1, today(), 5), 'date');
        $sorted = $dates;
        sort($sorted);

        $this->assertSame($sorted, $dates);
    }

    public function test_a_ledger_begun_mid_year_counts_from_its_first_whole_year(): void
    {
        // Opened in June on the balances brought in: seven months and an opening entry, which
        // the chart and the "since" figure would otherwise start from.
        $this->row($this->bank, 'deposit', '2019-06-10', '100');
        $this->row($this->bank, 'deposit', '2021-03-10', '50');

        $this->get('/net-worth')->assertInertia(fn (Assert $page) => $page
            ->where('since.date', '2020-01-31')
            ->where('history.0.date', '2020-12-31')
        );
    }

    public function test_the_page_takes_an_offered_spacing_and_ignores_any_other(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');

        $this->get('/net-worth?months=3')->assertInertia(fn (Assert $page) => $page
            ->component('net-worth')
            ->where('months', 3)
            ->where('base', 'HKD')
            ->where('current.cash', '100.0000')
            // A quarter back, because the badge is about the spacing the chart is drawn at.
            ->where('lastPeriod.date', '2026-06-30')
            ->where('since.date', '2026-02-28')
            ->has('history', 3)
        );

        $this->get('/net-worth?months=9')->assertInertia(fn (Assert $page) => $page
            ->where('months', 12)
            ->where('periods', [1, 3, 6, 12])
        );
    }

    public function test_the_badge_reads_the_spacing_the_chart_is_drawn_at(): void
    {
        $this->row($this->bank, 'deposit', '2019-06-10', '10000');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // Yearly is the default, so a badge reading "vs last month" under it answered a
        // question the page was not showing: the points below it are year ends.
        $this->get('/net-worth')->assertInertia(fn (Assert $page) => $page
            ->where('months', 12)
            ->where('lastPeriod.date', '2025-09-30')
            ->where('lastPeriod.change', '50.0000')
        );

        $this->get('/net-worth?months=1')->assertInertia(fn (Assert $page) => $page
            ->where('lastPeriod.date', '2026-08-31')
        );
    }

    public function test_a_ledger_too_young_to_reach_a_period_back_has_no_badge(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');

        // A year back is before the first transaction, where the balance is a standing zero. The
        // whole holding would read as a gain of itself, against a day the ledger does not reach.
        $this->get('/net-worth')->assertInertia(fn (Assert $page) => $page
            ->where('lastPeriod', null)
            // The window's own first is still a comparison, since the ledger does reach it.
            ->where('since.date', '2026-02-28')
        );
    }

    public function test_a_from_picks_where_the_history_starts(): void
    {
        $this->row($this->bank, 'deposit', '2019-01-10', '10');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // The whole ledger, which is the default and so is not echoed back.
        $this->get('/net-worth')->assertInertia(fn (Assert $page) => $page
            ->where('from', null)
            ->where('earliest', '2019-01')
            ->where('history.0.date', '2019-12-31')
            ->where('since.date', '2019-01-31')
        );

        $this->get('/net-worth?months=1&from=2026-02')->assertInertia(fn (Assert $page) => $page
            ->where('from', '2026-02')
            ->where('history.0.date', '2026-02-28')
            ->where('history.7.date', '2026-09-15')
            ->has('history', 8)
            ->where('since.date', '2026-02-28')
        );

        // Yearly is still year ends. A window drops the points before it rather than counting
        // the periods from it, so March does not become the month every twelve months lands
        // on and a yearly history quietly stops being yearly.
        $this->get('/net-worth?from=2024-03')->assertInertia(fn (Assert $page) => $page
            ->where('history.0.date', '2024-03-31')
            ->where('history.1.date', '2024-12-31')
            ->where('history.2.date', '2025-12-31')
            ->has('history', 4)
        );
    }

    public function test_a_from_is_the_month_it_names_at_any_spacing(): void
    {
        $this->row($this->bank, 'deposit', '2019-01-10', '10');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // January with half-year points between them: starting from the first half-year end
        // instead plotted from June while the control still read January, and nothing on
        // screen said which of the two had been asked for.
        $this->get('/net-worth?months=6&from=2024-01')->assertInertia(fn (Assert $page) => $page
            ->where('from', '2024-01')
            ->where('history.0.date', '2024-01-31')
            ->where('history.1.date', '2024-06-30')
            ->where('history.2.date', '2024-12-31')
        );

        // The same month as a period end is one point, not the opening month and then itself.
        $this->get('/net-worth?months=3&from=2024-04')->assertInertia(fn (Assert $page) => $page
            ->where('history.0.date', '2024-04-30')
            ->where('history.1.date', '2024-06-30')
        );

        // And a window shorter than its spacing still opens where it was asked to, rather
        // than on one lone point.
        $this->get('/net-worth?months=12&from=2026-02')->assertInertia(fn (Assert $page) => $page
            ->where('history.0.date', '2026-02-28')
            ->has('history', 2)
        );
    }

    public function test_a_month_the_ledger_cannot_show_is_the_whole_ledger(): void
    {
        $this->row($this->bank, 'deposit', '2019-01-10', '10');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // Thirteen is not a month, three are still to come, June 2018 is before the first
        // transaction, and a day is not a month: each is a window the data cannot show.
        foreach (['2019-13', '2027-03', '2018-06', 'soon', '2026-02-01'] as $month) {
            $this->get("/net-worth?from={$month}")->assertInertia(fn (Assert $page) => $page
                ->where('from', null)
                ->where('history.0.date', '2019-12-31')
            );
        }
    }

    public function test_the_window_end_is_one_point_not_two_on_a_month_end(): void
    {
        // Today on the last day of a month is itself a period end, and so is a window that
        // ends on one: appending the end unconditionally put the same day on the chart twice,
        // which draws as a flat step at the end of every window that lands on a month end.
        $this->travelTo(Carbon::parse('2026-09-30 12:00', 'Asia/Hong_Kong'));
        $this->row($this->bank, 'deposit', '2026-02-10', '100');

        $this->get('/net-worth?months=1')->assertInertia(fn (Assert $page) => $page
            ->where('history.0.date', '2026-02-28')
            ->where('history.7.date', '2026-09-30')
            ->has('history', 8)
        );

        $this->travelTo(Carbon::parse('2026-09-15 12:00', 'Asia/Hong_Kong'));
        $this->get('/net-worth?months=1&to=2026-06')->assertInertia(fn (Assert $page) => $page
            ->where('history.4.date', '2026-06-30')
            ->has('history', 5)
        );
    }

    public function test_a_window_ending_this_month_reads_today(): void
    {
        // This month has no end to read yet, so the cards sit on today. Every snapshot on the
        // page is a month end or today, which is what lets them all be asked for together --
        // a day inside a month would be given the whole month's movements.
        $this->row($this->bank, 'deposit', '2019-01-10', '10');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        $this->get('/net-worth?months=1&from=2026-02&to=2026-09')->assertInertia(fn (Assert $page) => $page
            ->where('at', null)
            ->where('history.0.date', '2026-02-28')
            ->where('history.7.date', '2026-09-15')
            ->has('history', 8)
        );
    }

    public function test_a_to_ends_the_window_where_it_is_asked_to(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // The cards read the window's end, so ending it in March is March's figures and
        // nothing after them.
        $this->get('/net-worth?months=1&to=2026-03')->assertInertia(fn (Assert $page) => $page
            ->where('to', '2026-03')
            ->where('at', null)
            ->where('snapshot', '2026-03-31')
            ->where('current.cash', '100.0000')
            ->where('lastPeriod.date', '2026-02-28')
            ->where('history.0.date', '2026-02-28')
            ->where('history.1.date', '2026-03-31')
            ->has('history', 2)
        );

        // Both ends together, which is what the two pickers are for.
        $this->get('/net-worth?months=1&from=2026-02&to=2026-04')->assertInertia(fn (Assert $page) => $page
            ->where('from', '2026-02')
            ->where('to', '2026-04')
            ->where('snapshot', '2026-04-30')
            ->where('history.0.date', '2026-02-28')
            ->where('history.2.date', '2026-04-30')
            ->has('history', 3)
        );
    }

    public function test_a_window_starting_this_month_compares_against_today(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // September's month end is still to come, so the badge cannot read against it, and a
        // comparison to a day the cards are not on is no comparison at all.
        $this->get('/net-worth?months=1&from=2026-09')->assertInertia(fn (Assert $page) => $page
            ->where('from', '2026-09')
            ->where('since.date', '2026-09-15')
            ->where('since.change', '0.0000')
        );
    }

    public function test_the_window_ends_are_read_as_one(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // An end before the start is not a shorter window but no window, so the end is dropped
        // and the page runs to today rather than asking for days that are not there.
        $this->get('/net-worth?months=1&from=2026-06&to=2026-03')->assertInertia(fn (Assert $page) => $page
            ->where('from', '2026-06')
            ->where('to', null)
            ->where('at', null)
            ->where('history.3.date', '2026-09-15')
            ->has('history', 4)
        );
    }

    public function test_an_at_reads_that_month_without_moving_the_window(): void
    {
        $this->row($this->bank, 'deposit', '2019-01-10', '10');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // Clicking a point on the chart sets this and not the window's end, so the months on
        // the right of the point it was clicked on are still there to be read: the whole of
        // 2019 to today is 92 month ends plus today.
        $this->get('/net-worth?months=1&at=2022-12')->assertInertia(fn (Assert $page) => $page
            ->where('at', '2022-12')
            ->where('to', null)
            ->where('snapshot', '2022-12-31')
            ->has('history', 93)
            ->where('history.47.date', '2022-12-31')
            ->where('history.92.date', '2026-09-15')
        );

        // A month is read on its last day, which is the only day of it the balances can
        // answer: a day inside a month silently took the whole month's movements.
        $this->get('/net-worth?months=1&at=2022-12')->assertInertia(fn (Assert $page) => $page
            ->where('current.cash', '10.0000')
            ->where('lastPeriod.date', '2022-11-30')
        );

        // A month still running has no last day yet, so it reads today.
        $this->get('/net-worth?months=1&at=2026-09')->assertInertia(fn (Assert $page) => $page
            ->where('at', '2026-09')
            ->where('snapshot', '2026-09-15')
        );
    }

    public function test_an_at_is_bounded_by_the_window_it_sits_in(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // Outside it in either direction is not read: the chart has no point there to mark,
        // and one before the window's end is the end itself.
        $this->get('/net-worth?months=1&from=2026-04&at=2026-03')->assertInertia(fn (Assert $page) => $page
            ->where('at', null)
            ->where('snapshot', '2026-09-15')
            ->where('current.cash', '150.0000')
        );

        $this->get('/net-worth?months=1&to=2026-04&at=2026-06')->assertInertia(fn (Assert $page) => $page
            ->where('at', null)
            ->where('snapshot', '2026-04-30')
        );

        // Inside it, and still no window moved.
        $this->get('/net-worth?months=1&from=2026-03&to=2026-07&at=2026-05')->assertInertia(fn (Assert $page) => $page
            ->where('from', '2026-03')
            ->where('to', '2026-07')
            ->where('at', '2026-05')
            ->where('snapshot', '2026-05-31')
            ->where('history.0.date', '2026-03-31')
            ->where('history.4.date', '2026-07-31')
            ->has('history', 5)
        );
    }

    public function test_a_month_the_ledger_cannot_show_is_no_snapshot(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // Still to come, not a month at all, before the first transaction, and a day where
        // only a month belongs: each leaves the page reading today.
        foreach (['2027-03', '2026-13', '2019-01', 'soon', '2026-05-31'] as $month) {
            $this->get("/net-worth?at={$month}")->assertInertia(fn (Assert $page) => $page
                ->where('at', null)
                ->where('snapshot', '2026-09-15')
                ->where('current.cash', '150.0000')
            );
        }
    }

    public function test_a_to_the_ledger_cannot_show_is_today(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        // Still to come, not a month at all, and before the first transaction.
        foreach (['2027-03', '2026-13', '2019-01', 'soon'] as $month) {
            $this->get("/net-worth?to={$month}")->assertInertia(fn (Assert $page) => $page
                ->where('to', null)
                ->where('snapshot', '2026-09-15')
                ->where('current.cash', '150.0000')
            );
        }
    }

    public function test_the_page_states_the_rate_a_foreign_row_was_converted_at(): void
    {
        $usd = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        $this->row($usd, 'deposit', '2026-02-10', '100');
        $this->price('USDHKD=X', '2026-08-20', '7.8', 'HKD');
        $this->price('USDHKD=X', '2026-09-15', '9', 'HKD');

        $this->get('/net-worth')->assertInertia(fn (Assert $page) => $page
            // The card's row shows the figure converted, so the page carries the rate that did
            // it: the only place one is ever stated rather than applied.
            ->where('rates', ['USD' => '9.0000'])
        );

        // The day the cards read, not today: a month picked on the chart was built at that
        // month's close, so the same page asked for an earlier month has to quote the rate of
        // the day it closed.
        $this->get('/net-worth?at=2026-08')->assertInertia(fn (Assert $page) => $page
            ->where('snapshot', '2026-08-31')
            ->where('rates', ['USD' => '7.8000'])
        );
    }

    private function row(Account $account, string $type, string $date, string $amount, array $extra = []): void
    {
        $this->post('/transactions', [
            'account_id' => $account->id,
            'date' => $date,
            'type' => $type,
            'description' => ucfirst($type),
            'amount' => $amount,
            'ccy' => $account->ccy,
            ...$extra,
        ])->assertSessionHasNoErrors();
    }

    private function buy(string $date, string $quantity, string $price): void
    {
        $this->post('/transactions', [
            'account_id' => $this->broker->id,
            'date' => $date,
            'type' => 'buy',
            'description' => 'Buy 0700.HK',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => '0700.HK', 'quantity' => $quantity, 'unit_price' => $price],
        ])->assertSessionHasNoErrors();
    }

    private function price(string $symbol, string $date, string $close, string $ccy): void
    {
        Price::create(['symbol' => $symbol, 'date' => $date, 'close' => $close, 'ccy' => $ccy, 'source' => 'manual']);
    }
}
