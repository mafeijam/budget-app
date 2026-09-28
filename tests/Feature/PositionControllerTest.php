<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
