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

    public function test_a_position_carries_its_average_cost_with_fees_kept_apart(): void
    {
        $this->trade('buy', '2026-01-05', '10', '100', '5');
        $this->trade('buy', '2026-01-06', '10', '120', '5');

        $nvda = Positions::forAccount($this->broker)['NVDA'];

        $this->assertSame('20.00000000', $nvda['quantity']);
        $this->assertSame('2200.0000', $nvda['cost']);
        $this->assertSame('110.0000', $nvda['average_cost']);
        $this->assertSame('10.0000', $nvda['fees']);
        $this->assertSame('0.0000', $nvda['realised']);
    }

    public function test_a_sell_realises_its_value_less_the_average_cost_it_removes(): void
    {
        // 5 at the 110 average cost 550; sold at 150 is 750. The fee goes to fees.
        $this->trade('buy', '2026-01-05', '10', '100', '5');
        $this->trade('buy', '2026-01-06', '10', '120', '5');
        $this->trade('sell', '2026-02-01', '5', '150', '5');

        $nvda = Positions::forAccount($this->broker)['NVDA'];

        $this->assertSame('15.00000000', $nvda['quantity']);
        $this->assertSame('1650.0000', $nvda['cost']);
        $this->assertSame('110.0000', $nvda['average_cost']);
        $this->assertSame('200.0000', $nvda['realised']);
        $this->assertSame('15.0000', $nvda['fees']);
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
    // What is held, for the dividend picker
    // ---------------------------------------------------------------------

    public function test_the_held_symbols_are_the_ones_still_open(): void
    {
        // The dividend picker offers this list, and the question it is asking is what the
        // brokerage owns now. A position sold out is not an answer -- a dividend is money
        // received on a holding already owned -- so it is the *open* positions and not the
        // whole book, which still carries what they realised.
        $this->trade('buy', '2026-01-05', '10', '100', null, 'nvda');
        $this->trade('buy', '2026-01-06', '10', '120', null, '0700.HK');
        $this->trade('sell', '2026-02-01', '10', '150', null, '0700.HK');

        $this->assertSame(['NVDA'], Positions::heldSymbols($this->broker));
        $this->assertSame(
            ['0700.HK', 'NVDA'],
            array_keys(Positions::forAccount($this->broker)),
            'The closed position is still in the book itself, so this is a narrowing and '
                .'not a different reading of the same thing.'
        );
    }

    public function test_a_brokerage_holding_nothing_offers_nothing(): void
    {
        // A brokerage with no trades is reachable -- DevAccountSeeder supplies two -- and
        // an empty list has to be an empty list, not a missing key: the form reads it with
        // ?? [] either way, but the picker must open rather than fail on it.
        $this->assertSame([], Positions::heldSymbols($this->broker));
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

        $this->assertSame(1, Transaction::where('type', 'buy')->count());
        $this->assertSame(0, Transaction::where('type', 'sell')->count());
    }

    public function test_a_buy_with_no_cash_side_still_holds_its_shares_and_still_guards_a_short_sell(): void
    {
        // The flag skips the money, not the shares: a position is replayed from the
        // trades, so skipping the bank row must not make a brokerage hold less.
        $this->trade('buy', '2026-01-05', '10', '100', noCash: true);

        $this->assertSame('10.00000000', Positions::forAccount($this->broker)['NVDA']['quantity']);

        $this->post('/transactions', $this->payload('sell', '2026-02-01', '15', '120', noCash: true))
            ->assertSessionHasErrors('meta_data.quantity');
    }

    public function test_a_sell_of_what_is_held_is_recorded(): void
    {
        $this->trade('buy', '2026-01-05', '10', '100');

        $this->post('/transactions', $this->payload('sell', '2026-02-01', '10', '120'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Transaction::whereIn('type', ['buy', 'sell'])->count());
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
        string $symbol = 'NVDA',
        bool $noCash = false
    ): Transaction {
        $this->post('/transactions', $this->payload($type, $date, $quantity, $price, $fees, $symbol, $noCash))
            ->assertSessionHasNoErrors();

        // The trade, not the cash row TradeCash writes after it.
        return Transaction::whereIn('type', ['buy', 'sell'])->latest('id')->firstOrFail();
    }

    private function payload(
        string $type,
        string $date,
        string $quantity,
        string $price,
        ?string $fees = null,
        string $symbol = 'NVDA',
        bool $noCash = false
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
                'no_cash' => $noCash ?: null,
            ], fn ($value) => $value !== null),
        ];
    }
}
