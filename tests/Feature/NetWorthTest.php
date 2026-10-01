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

    public function test_cash_less_the_cards_plus_the_stocks_at_their_last_close(): void
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
        $this->assertSame('10700.0000', $now['net_worth']);
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

    public function test_the_page_takes_an_offered_spacing_and_ignores_any_other(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');

        $this->get('/net-worth?months=3')->assertInertia(fn (Assert $page) => $page
            ->component('net-worth')
            ->where('months', 3)
            ->where('base', 'HKD')
            ->where('current.cash', '100.0000')
            ->where('lastMonth.date', '2026-08-31')
            ->where('since.date', '2026-02-28')
            ->has('history', 3)
        );

        $this->get('/net-worth?months=9')->assertInertia(fn (Assert $page) => $page
            ->where('months', 12)
            ->where('periods', [1, 3, 6, 12])
        );
    }

    public function test_a_picked_day_puts_its_snapshot_in_the_cards(): void
    {
        $this->row($this->bank, 'deposit', '2026-02-10', '100');
        $this->row($this->bank, 'deposit', '2026-05-10', '50');

        $this->get('/net-worth?at=2026-03-31')->assertInertia(fn (Assert $page) => $page
            ->where('at', '2026-03-31')
            ->where('current.cash', '100.0000')
            ->where('lastMonth.date', '2026-02-28')
            ->where('lastMonth.change', '0.0000')
        );

        // A future or malformed day is today's page.
        foreach (['2026-12-31', '2026-02-30', 'soon'] as $day) {
            $this->get("/net-worth?at={$day}")->assertInertia(fn (Assert $page) => $page
                ->where('at', null)
                ->where('current.cash', '150.0000')
            );
        }
    }

    public function test_the_page_states_the_rate_a_foreign_row_was_converted_at(): void
    {
        $usd = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        $this->row($usd, 'deposit', '2026-02-10', '100');
        $this->price('USDHKD=X', '2026-09-11', '7.8', 'HKD');
        $this->price('USDHKD=X', '2026-09-15', '9', 'HKD');

        $this->get('/net-worth')->assertInertia(fn (Assert $page) => $page
            // The card's row shows the figure converted, so the page carries the rate that did
            // it: the only place one is ever stated rather than applied.
            ->where('rates', ['USD' => '9.0000'])
        );

        // The day the cards read, not today: a snapshot picked on the chart was built at that
        // day's rate, so the same page asked for an earlier day has to quote the earlier rate.
        $this->get('/net-worth?at=2026-09-11')->assertInertia(fn (Assert $page) => $page
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
