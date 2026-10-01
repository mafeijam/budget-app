<?php

namespace Tests\Feature;

use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dividends a year paid, month by month and symbol by symbol, beside the year before.
 */
class DividendPageTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $broker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-01-20 12:00', 'Asia/Hong_Kong'));

        $this->bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);
    }

    public function test_a_year_is_split_by_month_and_symbol_beside_the_year_before(): void
    {
        $this->dividend('2025-03-10', '0005.HK', '50');
        $this->dividend('2025-09-10', '0005.HK', '70');
        $this->dividend('2025-09-12', '0700.HK', '30');
        $this->dividend('2024-09-10', '0005.HK', '40');

        $this->get('/dividends?year=2025')->assertInertia(fn (Assert $page) => $page
            ->component('dividend')
            ->where('year', 2025)
            ->where('total', '150.0000')
            ->where('previous', '40.0000')
            ->where('payments', 3)
            ->where('months.2', '50.0000')
            ->where('months.8', '100.0000')
            ->where('previousMonths.8', '40.0000')
            // Largest first, each with its own months and last year's figure.
            ->where('symbols.0.symbol', '0005.HK')
            ->where('symbols.0.total', '120.0000')
            ->where('symbols.0.previous', '40.0000')
            ->where('symbols.0.brokers', ['Broker'])
            ->where('symbols.1.symbol', '0700.HK')
            ->where('symbols.1.months.8', '30.0000')
            // Not this year, so nothing is expected on it.
            ->where('expected', '0.0000')
            ->where('years', [
                ['year' => 2026, 'total' => '0.0000', 'bySymbol' => []],
                ['year' => 2025, 'total' => '150.0000', 'bySymbol' => ['0005.HK' => '120.0000', '0700.HK' => '30.0000']],
                ['year' => 2024, 'total' => '40.0000', 'bySymbol' => ['0005.HK' => '40.0000']],
            ])
        );
    }

    public function test_each_year_carries_what_each_symbol_paid_in_it(): void
    {
        $this->dividend('2025-03-10', '0005.HK', '50');
        $this->dividend('2025-09-10', '0005.HK', '70');
        $this->dividend('2025-09-12', '0700.HK', '30');
        $this->dividend('2024-09-10', '0005.HK', '40');

        $this->get('/dividends?year=2025')->assertInertia(fn (Assert $page) => $page
            // The bars are filtered by symbol, so each year has to say what each symbol
            // paid in it -- not only the year as a whole, which is all it carried before.
            ->where('years.0.year', 2026)
            ->where('years.0.bySymbol', [])
            ->where('years.1.year', 2025)
            ->where('years.1.total', '150.0000')
            ->where('years.1.bySymbol', ['0005.HK' => '120.0000', '0700.HK' => '30.0000'])
            ->where('years.2.bySymbol', ['0005.HK' => '40.0000'])
            // A symbol that stopped paying is still in the filter's list, with what it
            // paid in all and how many of the years it paid in -- and the list is in that
            // order, biggest first, because it is the order the colours come from.
            ->where('allSymbols', [
                ['symbol' => '0005.HK', 'name' => null, 'total' => '160.0000', 'years' => 2],
                ['symbol' => '0700.HK', 'name' => null, 'total' => '30.0000', 'years' => 1],
            ])
        );
    }

    public function test_the_symbols_are_ranked_by_all_time_so_a_colour_means_one_symbol_on_every_year(): void
    {
        // 0700 has paid the most overall and 0005 the least, so this list is the reverse
        // of the alphabetical order the symbols are read in -- which is the point. The
        // page takes a symbol's colour from its place in this list, so if the list were in
        // any other order the colours would follow it, and ranking it by the year on screen
        // instead would give a different colour to the same symbol each time the year
        // changed. The 2024 view is included because 0005 leads 2025 on that year's own
        // figures and 0700 leads 2024: ranked per year the two swap, and the colours with
        // them.
        $this->dividend('2025-03-10', '0005.HK', '60');
        $this->dividend('2024-09-10', '0005.HK', '10');
        $this->dividend('2025-09-12', '0700.HK', '10');
        $this->dividend('2024-09-12', '0700.HK', '60');
        $this->dividend('2023-09-12', '0700.HK', '5');

        $order = ['0700.HK', '0005.HK'];

        foreach ([2025, 2024] as $year) {
            $this->get("/dividends?year={$year}")->assertInertia(fn (Assert $page) => $page
                ->where('allSymbols', fn ($all) => array_column($all->all(), 'symbol') === $order)
                ->where('allSymbols.0.total', '75.0000')
                ->where('allSymbols.1.total', '70.0000')
            );
        }
    }

    public function test_the_expected_top_on_the_current_year_is_split_by_symbol(): void
    {
        // The dashed top on this year's bar is a whole-year figure, so a symbol filtered
        // onto that bar needs its own share: every symbol's on one bar would be a lie. It
        // takes a holding to be expected at all -- the forecast projects last year's
        // payment a year on for what is held now -- so both symbols are bought.
        $this->buy('2025-01-01', '0005.HK', '100');
        $this->buy('2025-01-01', '0700.HK', '100');
        $this->dividend('2025-03-10', '0005.HK', '20');
        $this->dividend('2025-07-10', '0005.HK', '20');
        $this->dividend('2025-03-10', '0700.HK', '10');
        // This year's own, already paid and in the bars rather than on top of them. Kept
        // clear of both projected days: the forecast drops an expectation when a payment
        // is already on file near the day it is projected, so a January payment here would
        // silence 0005.HK's March one and the figures would be right for the wrong reason.
        $this->dividend('2026-01-05', '0005.HK', '50');

        $this->get('/dividends')->assertInertia(fn (Assert $page) => $page
            ->where('year', 2026)
            // A map keyed by symbol rather than the single figure the page carries, which
            // is their sum and stays as it is.
            ->where('expectedBySymbol', ['0005.HK' => '40.0000', '0700.HK' => '10.0000'])
            ->where('expected', '50.0000')
            ->where('years.0.year', 2026)
            ->where('years.0.total', '50.0000')
            ->where('years.0.bySymbol', ['0005.HK' => '50.0000'])
        );
    }

    public function test_a_year_with_no_dividends_is_this_year_rather_than_an_error(): void
    {
        $this->get('/dividends?year=1999')->assertInertia(fn (Assert $page) => $page
            ->where('year', 2026)
            ->where('symbols', [])
        );
    }

    public function test_a_brokerage_narrows_the_page_to_what_its_holdings_paid(): void
    {
        $other = $this->otherBroker();

        $this->dividend('2025-03-10', '0005.HK', '50');
        $this->dividend('2024-03-10', '0005.HK', '20');
        $this->dividend('2025-09-12', '0700.HK', '30', $other);

        $this->get("/dividends?year=2025&broker={$other->id}")->assertInertia(fn (Assert $page) => $page
            ->where('broker', $other->id)
            ->where('total', '30.0000')
            ->where('payments', 1)
            ->where('symbols.0.symbol', '0700.HK')
            ->where('symbols.0.brokers', ['Other broker'])
            ->has('symbols', 1)
            // The symbols it ever paid, not the page's: the picker lists what there is to pick.
            ->where('allSymbols', fn ($all) => array_column($all->all(), 'symbol') === ['0700.HK'])
            // The year list is every year there was a payment in, whichever brokerage: the
            // other one's 2024 is an empty column here, not a year that went missing.
            ->where('years', [
                ['year' => 2026, 'total' => '0.0000', 'bySymbol' => []],
                ['year' => 2025, 'total' => '30.0000', 'bySymbol' => ['0700.HK' => '30.0000']],
                ['year' => 2024, 'total' => '0.0000', 'bySymbol' => []],
            ])
            // The picker never narrows to itself, by name, and carries what each has paid in all.
            ->where('brokers', [
                ['id' => $this->broker->id, 'name' => 'Broker', 'total' => '70.0000'],
                ['id' => $other->id, 'name' => 'Other broker', 'total' => '30.0000'],
            ])
        );

        $this->get("/dividends?year=2025&broker={$this->broker->id}")->assertInertia(fn (Assert $page) => $page
            ->where('total', '50.0000')
            // The year before is narrowed too, or the comparison would set one brokerage's
            // year against everyone's: the other one paid nothing in 2024.
            ->where('previous', '20.0000')
            ->where('symbols.0.symbol', '0005.HK')
            ->has('symbols', 1)
        );
    }

    public function test_a_brokerage_that_names_none_is_all_of_them(): void
    {
        $this->dividend('2025-03-10', '0005.HK', '50');
        $this->dividend('2025-09-12', '0700.HK', '30', $this->otherBroker());

        // A hand-edited URL, a brokerage that has never paid, and the one id that is an
        // account but no brokerage of this page's: none is an empty page.
        foreach ([999, $this->bank->id, 0] as $id) {
            $this->get("/dividends?year=2025&broker={$id}")->assertInertia(fn (Assert $page) => $page
                ->where('broker', 0)
                ->where('total', '80.0000')
            );
        }
    }

    public function test_a_brokerage_is_only_offered_where_it_has_paid(): void
    {
        $this->otherBroker();
        $this->dividend('2025-03-10', '0005.HK', '50');

        $this->get('/dividends?year=2025')->assertInertia(fn (Assert $page) => $page
            ->where('brokers', [['id' => $this->broker->id, 'name' => 'Broker', 'total' => '50.0000']])
        );
    }

    public function test_the_expected_top_is_the_brokerages_own(): void
    {
        $other = $this->otherBroker();

        $this->buy('2025-01-01', '0005.HK', '100');
        $this->buy('2025-01-01', '0700.HK', '100', $other);
        $this->dividend('2025-03-10', '0005.HK', '20');
        $this->dividend('2025-03-10', '0700.HK', '10', $other);

        $this->get('/dividends')->assertInertia(fn (Assert $page) => $page
            ->where('expected', '30.0000')
        );

        // A brokerage is expected to pay what its own holdings paid a year ago, and nothing
        // of what another's will.
        $this->get("/dividends?broker={$other->id}")->assertInertia(fn (Assert $page) => $page
            ->where('expected', '10.0000')
            ->where('expectedBySymbol', ['0700.HK' => '10.0000'])
        );

        $this->get("/dividends?broker={$this->broker->id}")->assertInertia(fn (Assert $page) => $page
            ->where('expected', '20.0000')
            ->where('expectedBySymbol', ['0005.HK' => '20.0000'])
        );
    }

    public function test_the_cost_is_what_was_held_on_the_last_day_of_the_year(): void
    {
        // A dividend in each year, so each is a year the page can be of.
        $this->dividend('2024-03-10', '0005.HK', '5');
        $this->dividend('2025-03-10', '0005.HK', '5');
        $this->dividend('2026-01-10', '0005.HK', '5');

        $this->buy('2024-06-01', '0005.HK', '100');
        $this->buy('2025-06-01', '0005.HK', '50');
        $this->buy('2026-01-15', '0005.HK', '100');

        // Each year against the capital it was paid on: today's 2,500 under 2024's money would
        // be a yield on shares bought a year and a half later.
        foreach ([2024 => '1000.0000', 2025 => '1500.0000', 2026 => '2500.0000'] as $year => $cost) {
            $this->get("/dividends?year={$year}")->assertInertia(fn (Assert $page) => $page
                ->where('cost', $cost)
                ->where('costUnconverted', [])
            );
        }
    }

    public function test_the_cost_leaves_out_what_was_sold_and_is_the_brokerages_own(): void
    {
        $other = $this->otherBroker();

        $this->dividend('2025-03-10', '0005.HK', '5');
        $this->dividend('2025-03-10', '0700.HK', '5', $other);

        $this->buy('2025-01-02', '0005.HK', '100');
        $this->buy('2025-01-02', '0700.HK', '30', $other);
        $this->sell('2025-02-01', '0005.HK', '40');

        // 60 left of the first at 10, and all 30 of the other: sold shares are no longer cost.
        $this->get('/dividends?year=2025')->assertInertia(fn (Assert $page) => $page
            ->where('cost', '900.0000')
        );

        $this->get("/dividends?year=2025&broker={$other->id}")->assertInertia(fn (Assert $page) => $page
            ->where('cost', '300.0000')
        );

        $this->get("/dividends?year=2025&broker={$this->broker->id}")->assertInertia(fn (Assert $page) => $page
            ->where('cost', '600.0000')
        );
    }

    public function test_a_cost_in_a_currency_with_no_rate_is_no_figure_and_says_so(): void
    {
        $usd = Account::create(['name' => 'Broker US', 'status' => 'active', 'type' => 'security', 'ccy' => 'USD']);
        $usd->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $this->dividend('2025-03-10', '0005.HK', '5');
        $this->buy('2025-01-02', '0005.HK', '100');
        $this->post('/transactions', [
            'account_id' => $usd->id, 'date' => '2025-01-02', 'type' => 'buy', 'description' => 'Buy',
            'ccy' => 'USD', 'status' => 'posted',
            'meta_data' => ['symbol' => 'AAPL', 'quantity' => '10', 'unit_price' => '10', 'no_cash' => true],
        ])->assertSessionHasNoErrors();

        // Null, not the HKD part alone: a cost missing a brokerage is a yield on less than was
        // held, which reads higher than the truth and gives no sign of it.
        $this->get('/dividends?year=2025')->assertInertia(fn (Assert $page) => $page
            ->where('cost', null)
            ->where('costUnconverted', ['USD'])
        );

        // The HKD brokerage on its own has every rate it needs.
        $this->get("/dividends?year=2025&broker={$this->broker->id}")->assertInertia(fn (Assert $page) => $page
            ->where('cost', '1000.0000')
            ->where('costUnconverted', [])
        );
    }

    private function otherBroker(): Account
    {
        $other = Account::create(['name' => 'Other broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $other->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        return $other;
    }

    private function dividend(string $date, string $symbol, string $amount, ?Account $broker = null): void
    {
        $this->post('/transactions', [
            'account_id' => $this->bank->id, 'date' => $date, 'type' => 'dividend', 'description' => 'Dividend',
            'amount' => $amount, 'ccy' => 'HKD', 'status' => 'posted',
            'meta_data' => ['symbol' => $symbol, 'brokerage_account_id' => ($broker ?? $this->broker)->id],
        ])->assertSessionHasNoErrors();
    }

    private function sell(string $date, string $symbol, string $quantity): void
    {
        $this->post('/transactions', [
            'account_id' => $this->broker->id, 'date' => $date, 'type' => 'sell', 'description' => 'Sell',
            'ccy' => 'HKD', 'status' => 'posted',
            'meta_data' => [
                'symbol' => $symbol, 'quantity' => $quantity, 'unit_price' => '10', 'no_cash' => true,
            ],
        ])->assertSessionHasNoErrors();
    }

    /**
     * Held, because the forecast only expects a dividend for a position -- a symbol paid
     * and no longer held projects nothing, so the page's expected figures stay empty.
     */
    private function buy(string $date, string $symbol, string $quantity, ?Account $broker = null): void
    {
        $this->post('/transactions', [
            'account_id' => ($broker ?? $this->broker)->id, 'date' => $date, 'type' => 'buy', 'description' => 'Buy',
            'ccy' => 'HKD', 'status' => 'posted',
            'meta_data' => [
                'symbol' => $symbol, 'quantity' => $quantity, 'unit_price' => '10', 'no_cash' => true,
            ],
        ])->assertSessionHasNoErrors();
    }
}
