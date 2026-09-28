<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Price;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Positions page: each brokerage and what it holds, read from its trades.
 */
class PositionControllerTest extends TestCase
{
    use RefreshDatabase;

    private Account $broker;

    protected function setUp(): void
    {
        parent::setUp();

        $bank = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        $this->broker = Account::create(['name' => 'Broker USD', 'status' => 'active', 'type' => 'security', 'ccy' => 'USD']);
        $this->broker->meta()->create(['meta' => ['settlement_account_id' => $bank->id]]);
    }

    public function test_it_lists_each_brokerage_with_its_positions_and_totals(): void
    {
        $this->trade('buy', '2026-01-05', 'NVDA', '10', '100');
        $this->trade('buy', '2026-01-06', 'AAPL', '2', '200');
        $this->trade('sell', '2026-02-01', 'AAPL', '2', '250');

        $this->get('/positions')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('position')
            ->has('brokerages', 1)
            ->where('brokerages.0.name', 'Broker USD')
            ->where('brokerages.0.ccy', 'USD')
            ->where('brokerages.0.settles_into', 'Bank USD')
            // Symbol order, sold-out ones included for the toggle to show.
            ->where('brokerages.0.positions', fn ($positions) => $positions->pluck('symbol')->all() === ['AAPL', 'NVDA'])
            ->where('brokerages.0.positions.0.open', false)
            ->where('brokerages.0.positions.1.quantity', '10.00000000')
            // Only what is still held counts toward the cost.
            ->where('brokerages.0.open_cost', '1000.0000')
            ->where('brokerages.0.realised', '100.0000')
        );
    }

    public function test_an_open_position_is_valued_at_its_latest_price(): void
    {
        $this->travelTo(Carbon::parse('2026-03-06 12:00', 'Asia/Hong_Kong'));

        $this->trade('buy', '2026-01-05', 'NVDA', '10', '100');
        $this->trade('buy', '2026-01-05', 'AAPL', '1', '200');

        Price::create(['symbol' => 'NVDA', 'date' => '2026-03-04', 'close' => '120.0000', 'ccy' => 'USD', 'source' => 'yahoo']);
        Price::create(['symbol' => 'NVDA', 'date' => '2026-03-05', 'close' => '125.5000', 'ccy' => 'USD', 'source' => 'yahoo']);
        // Tomorrow's is not today's latest.
        Price::create(['symbol' => 'NVDA', 'date' => '2026-03-07', 'close' => '999.0000', 'ccy' => 'USD', 'source' => 'yahoo']);

        $this->get('/positions')->assertInertia(fn (Assert $page) => $page
            ->where('brokerages.0.positions.1.symbol', 'NVDA')
            ->where('brokerages.0.positions.1.price', '125.5000')
            ->where('brokerages.0.positions.1.price_date', '2026-03-05')
            ->where('brokerages.0.positions.1.market_value', '1255.0000')
            ->where('brokerages.0.positions.1.unrealised', '255.0000')
            // AAPL has no price, so the totals are over NVDA alone and say so.
            ->where('brokerages.0.positions.0.market_value', null)
            ->where('brokerages.0.market_value', '1255.0000')
            ->where('brokerages.0.unrealised', '255.0000')
            ->where('brokerages.0.unpriced', 1)
        );
    }

    public function test_a_price_in_another_currency_is_not_used(): void
    {
        $this->trade('buy', '2026-01-05', 'NVDA', '10', '100');

        Price::create(['symbol' => 'NVDA', 'date' => today()->toDateString(), 'close' => '900.0000', 'ccy' => 'HKD', 'source' => 'yahoo']);

        $this->get('/positions')->assertInertia(fn (Assert $page) => $page
            ->where('brokerages.0.positions.0.market_value', null)
            ->where('brokerages.0.unpriced', 1)
        );
    }

    public function test_a_price_set_by_hand_is_filed_for_today_and_marked_manual(): void
    {
        $this->travelTo(Carbon::parse('2026-03-06 12:00', 'Asia/Hong_Kong'));
        $this->trade('buy', '2026-01-05', 'NVDA', '10', '100');

        $this->post('/prices', ['account_id' => $this->broker->id, 'symbol' => 'nvda', 'close' => '130.25'])
            ->assertSessionHasNoErrors();

        $price = Price::sole();

        $this->assertSame(['NVDA', '2026-03-06', '130.2500', 'USD', 'manual'], [
            $price->symbol, $price->date, $price->close, $price->ccy, $price->source,
        ]);
    }

    public function test_a_price_set_by_hand_must_be_a_positive_figure_on_a_brokerage(): void
    {
        $bank = Account::where('type', 'cash')->firstOrFail();

        $this->post('/prices', ['account_id' => $bank->id, 'symbol' => 'NVDA', 'close' => '-1'])
            ->assertSessionHasErrors(['account_id', 'close']);

        $this->assertSame(0, Price::count());
    }

    public function test_the_fetch_button_runs_the_fetch_and_reports_each_symbol(): void
    {
        $this->travelTo(Carbon::parse('2026-03-06 12:00', 'Asia/Hong_Kong'));
        $this->trade('buy', '2026-01-05', 'NVDA', '10', '100');

        Http::fake(['query1.finance.yahoo.com/*' => Http::response(['chart' => ['result' => [[
            'meta' => ['currency' => 'USD', 'exchangeTimezoneName' => 'America/New_York'],
            'timestamp' => [Carbon::parse('2026-03-05 21:00', 'UTC')->timestamp],
            'indicators' => ['quote' => [['close' => [130.5]]]],
        ]], 'error' => null]])]);

        $this->get('/positions')->assertInertia(fn (Assert $page) => $page->where('pricesUpdatedAt', null));

        $this->post('/prices/fetch')->assertSessionHas('message', 'Prices fetched: NVDA: 1 day');

        $this->assertSame('130.5000', Price::where('symbol', 'NVDA')->sole()->close);

        // How fresh the table is, for the caption beside the button.
        $this->get('/positions')->assertInertia(fn (Assert $page) => $page
            ->where('pricesUpdatedAt', '2026-03-06T12:00:00+08:00')
        );
    }

    public function test_the_fetch_button_says_when_a_symbol_was_not_fetched(): void
    {
        $this->trade('buy', '2026-01-05', 'NVDA', '10', '100');

        Http::fake(['*' => Http::response(['chart' => ['result' => null, 'error' => ['description' => 'Not found']]], 404)]);

        $this->post('/prices/fetch')->assertSessionHas(
            'message',
            fn (string $message) => str_starts_with($message, 'Some prices were not fetched: Yahoo did not return prices for NVDA')
        );
    }

    public function test_only_received_dividends_are_totalled(): void
    {
        foreach (['posted' => '12.5000', 'pending' => '99.0000'] as $status => $amount) {
            $this->post('/transactions', [
                'account_id' => $this->broker->id,
                'date' => '2026-03-01',
                'type' => 'dividend',
                'description' => 'Dividend',
                'amount' => $amount,
                'ccy' => 'USD',
                'status' => $status,
            ])->assertSessionHasNoErrors();
        }

        $this->get('/positions')->assertInertia(fn (Assert $page) => $page
            ->where('brokerages.0.dividends', '12.5000')
        );
    }

    public function test_a_closed_brokerage_shows_only_while_it_holds_shares(): void
    {
        $this->trade('buy', '2026-01-05', 'NVDA', '1', '100');
        $this->broker->update(['status' => 'inactive']);

        Account::create(['name' => 'Old broker', 'status' => 'inactive', 'type' => 'security', 'ccy' => 'USD']);

        $this->get('/positions')->assertInertia(fn (Assert $page) => $page
            ->where('brokerages', fn ($brokerages) => $brokerages->pluck('name')->all() === ['Broker USD'])
        );
    }

    private function trade(string $type, string $date, string $symbol, string $quantity, string $price): void
    {
        $this->post('/transactions', [
            'account_id' => $this->broker->id,
            'date' => $date,
            'type' => $type,
            'description' => "{$type} {$symbol}",
            'ccy' => 'USD',
            'meta_data' => ['symbol' => $symbol, 'quantity' => $quantity, 'unit_price' => $price],
        ])->assertSessionHasNoErrors();
    }
}
