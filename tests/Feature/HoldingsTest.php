<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\Positions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What a brokerage holds, derived from its trades, and the refusals that keep it from
 * selling what it does not hold.
 */
class HoldingsTest extends TestCase
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

    // ---------------------------------------------------------------------
    // The arithmetic
    // ---------------------------------------------------------------------

    public function test_a_position_carries_its_average_cost_including_fees(): void
    {
        $this->trade('buy', '2026-01-05', '10', '100', '5');
        $this->trade('buy', '2026-01-06', '10', '120', '5');

        $nvda = Positions::forAccount($this->broker)['NVDA'];

        $this->assertSame('20.00000000', $nvda['quantity']);
        $this->assertSame('2210.0000', $nvda['cost']);
        $this->assertSame('110.5000', $nvda['average_cost']);
        $this->assertSame('0.0000', $nvda['realised']);
    }

    public function test_a_sell_realises_its_proceeds_less_the_average_cost_it_removes(): void
    {
        // 5 at the 110.50 average cost 552.50; sold at 150 less a 5 fee is 745.
        $this->trade('buy', '2026-01-05', '10', '100', '5');
        $this->trade('buy', '2026-01-06', '10', '120', '5');
        $this->trade('sell', '2026-02-01', '5', '150', '5');

        $nvda = Positions::forAccount($this->broker)['NVDA'];

        $this->assertSame('15.00000000', $nvda['quantity']);
        $this->assertSame('1657.5000', $nvda['cost']);
        $this->assertSame('110.5000', $nvda['average_cost']);
        $this->assertSame('192.5000', $nvda['realised']);
    }

    public function test_a_position_sold_out_keeps_what_it_realised(): void
    {
        $this->trade('buy', '2026-01-05', '3', '10');
        $this->trade('sell', '2026-02-01', '3', '12');

        $nvda = Positions::forAccount($this->broker)['NVDA'];

        $this->assertFalse($nvda['open']);
        $this->assertSame('0.0000', $nvda['cost']);
        $this->assertNull($nvda['average_cost']);
        $this->assertSame('6.0000', $nvda['realised']);
    }

    public function test_a_symbol_is_one_position_whatever_its_case_or_spacing(): void
    {
        $this->trade('buy', '2026-01-05', '1', '10', null, 'nvda');
        $this->trade('buy', '2026-01-06', '1', '10', null, ' NVDA ');

        $this->assertSame(['NVDA'], array_keys(Positions::forAccount($this->broker)));
    }

    // ---------------------------------------------------------------------
    // Selling what is not held
    // ---------------------------------------------------------------------

    public function test_a_sell_of_more_than_is_held_is_refused(): void
    {
        $this->trade('buy', '2026-01-05', '10', '100');

        $this->post('/transactions', $this->payload('sell', '2026-02-01', '15', '120'))
            ->assertSessionHasErrors([
                'meta_data.quantity' => 'That leaves a sell of 15 NVDA on 2026-02-01 with only 10 held that '
                    .'day. A sell cannot be more than is held.',
            ]);

        $this->assertSame(1, Transaction::count());
    }

    public function test_a_sell_of_what_is_held_is_recorded(): void
    {
        $this->trade('buy', '2026-01-05', '10', '100');

        $this->post('/transactions', $this->payload('sell', '2026-02-01', '10', '120'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Transaction::count());
    }

    public function test_a_sell_dated_before_the_buy_it_sells_from_is_refused(): void
    {
        // The totals would balance; the day it was sold, nothing was held.
        $this->trade('buy', '2026-03-01', '10', '100');

        $this->post('/transactions', $this->payload('sell', '2026-02-01', '5', '120'))
            ->assertSessionHasErrors('meta_data.quantity');
    }

    public function test_a_buy_cannot_be_cut_below_what_a_later_sell_sold(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '10', '100');
        $this->trade('sell', '2026-02-01', '8', '120');

        $this->put("/transactions/{$buy->id}", $this->payload('buy', '2026-01-05', '5', '100'))
            ->assertSessionHasErrors('meta_data.quantity');

        $this->assertSame('10', $buy->fresh()->meta->meta['quantity']);
    }

    public function test_a_buy_a_later_sell_depends_on_cannot_be_deleted(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '10', '100');
        $this->trade('sell', '2026-02-01', '8', '120');

        $refusal = 'Deleting this buy leaves the sell of 8 NVDA on 2026-02-01 with only 0 held. Delete or '
            .'reduce that sell first.';

        $this->get('/transactions?per_page=10')->assertInertia(fn (Assert $page) => $page
            ->where("refusals.{$buy->id}", $refusal)
        );

        $this->delete("/transactions/{$buy->id}")->assertSessionHas('message', $refusal);

        $this->assertNotNull($buy->fresh());
    }

    public function test_a_buy_no_sell_depends_on_deletes_normally(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '10', '100');
        $this->trade('buy', '2026-01-06', '10', '100');
        $this->trade('sell', '2026-02-01', '8', '120');

        $this->delete("/transactions/{$buy->id}");

        $this->assertNull($buy->fresh());
    }

    // ---------------------------------------------------------------------

    /** A trade recorded through the form, so it passes every guard a real one does. */
    private function trade(
        string $type,
        string $date,
        string $quantity,
        string $price,
        ?string $fees = null,
        string $symbol = 'NVDA'
    ): Transaction {
        $this->post('/transactions', $this->payload($type, $date, $quantity, $price, $fees, $symbol))
            ->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function payload(
        string $type,
        string $date,
        string $quantity,
        string $price,
        ?string $fees = null,
        string $symbol = 'NVDA'
    ): array {
        return [
            'account_id' => $this->broker->id,
            'date' => $date,
            'type' => $type,
            'description' => "{$type} {$symbol}",
            'ccy' => 'USD',
            'meta_data' => array_filter([
                'symbol' => $symbol,
                'quantity' => $quantity,
                'unit_price' => $price,
                'fees' => $fees,
            ], fn ($value) => $value !== null),
        ];
    }
}
