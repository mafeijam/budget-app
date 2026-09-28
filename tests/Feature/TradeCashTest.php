<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\AccountBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The cash side of a trade: the row in the brokerage's settlement account that a buy
 * takes money out of and a sell pays into, written and kept by TradeCash.
 */
class TradeCashTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $broker;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->bank, $this->broker] = $this->brokerage('Broker USD', 'Bank USD');

        // Money in the bank, so a buy has something to come out of.
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-01-01',
            'type' => 'income',
            'description' => 'Salary',
            'amount' => '10000.0000',
            'ccy' => 'USD',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_buy_takes_its_cost_out_of_the_settlement_bank(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '10', '100', '5');

        $cash = $this->cashOf($buy);

        $this->assertSame($this->bank->id, $cash->account_id);
        $this->assertSame('transfer', $cash->type);
        $this->assertSame('1005.0000', $cash->amount);
        $this->assertSame('2026-01-05', $cash->date);
        $this->assertSame('Buy 10 NVDA [Broker USD]', $cash->description);
        $this->assertSame($buy->id, (int) $cash->meta->meta['paired_transaction_id']);

        $this->assertSame('8995.0000', $this->balance());
    }

    public function test_a_sell_pays_its_net_proceeds_into_the_bank_as_a_deposit_not_income(): void
    {
        $this->trade('buy', '2026-01-05', '10', '100');
        $sell = $this->trade('sell', '2026-02-01', '10', '120', '5');

        $cash = $this->cashOf($sell);

        $this->assertSame('deposit', $cash->type);
        $this->assertSame('1195.0000', $cash->amount);
        $this->assertSame('10195.0000', $this->balance());
    }

    public function test_the_cash_follows_an_edit_to_the_trade(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '10', '100');

        $this->put("/transactions/{$buy->id}", $this->payload('buy', '2026-01-08', '12', '100', null, 'pending'))
            ->assertSessionHasNoErrors();

        $cash = $this->cashOf($buy->fresh());

        $this->assertSame('1200.0000', $cash->amount);
        $this->assertSame('2026-01-08', $cash->date);
        $this->assertSame('pending', $cash->status);
        $this->assertSame(1, Transaction::where('type', 'transfer')->count(), 'The edit wrote a second cash row.');
    }

    public function test_moving_a_trade_to_another_brokerage_moves_its_cash_to_that_bank(): void
    {
        [$otherBank, $otherBroker] = $this->brokerage('Other broker', 'Other bank');
        $buy = $this->trade('buy', '2026-01-05', '1', '100');

        $this->put("/transactions/{$buy->id}", array_merge(
            $this->payload('buy', '2026-01-05', '1', '100'),
            ['account_id' => $otherBroker->id]
        ))->assertSessionHasNoErrors();

        $this->assertSame($otherBank->id, $this->cashOf($buy->fresh())->account_id);
        $this->assertSame('10000.0000', $this->balance());
    }

    public function test_a_trade_edited_into_a_dividend_loses_its_cash(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '1', '100');

        $this->put("/transactions/{$buy->id}", [
            'account_id' => $this->broker->id,
            'date' => '2026-01-05',
            'type' => 'dividend',
            'description' => 'Dividend',
            'amount' => '3.0000',
            'ccy' => 'USD',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, Transaction::where('type', 'transfer')->count());
        $this->assertArrayNotHasKey('paired_transaction_id', $buy->fresh()->meta?->meta?->getArrayCopy() ?? []);
    }

    public function test_the_cash_side_is_locked_to_its_trade(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '10', '100');
        $cash = $this->cashOf($buy);

        $this->put("/transactions/{$cash->id}", [
            'account_id' => $this->bank->id,
            'date' => $cash->date,
            'type' => 'transfer',
            'description' => $cash->description,
            'amount' => '1.0000',
            'ccy' => 'USD',
        ])->assertSessionHasErrors([
            'amount' => 'This transfer is the cash side of the trade Buy 10 NVDA [Broker USD], so its '
                .'amount cannot be changed here. Edit the trade instead.',
        ]);

        // And the form says so before anyone tries; the trade itself carries no lock.
        $this->get('/transactions?per_page=10')->assertInertia(fn (Assert $page) => $page
            ->where("editLocks.{$cash->id}.fields", ['account_id', 'type', 'date', 'amount', 'ccy', 'status'])
            ->missing("editLocks.{$buy->id}")
            ->where("linked.{$buy->id}.kind", 'trade')
        );
    }

    public function test_the_cash_side_cannot_be_deleted_alone_and_goes_with_its_trade(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '10', '100');
        $cash = $this->cashOf($buy);

        $this->delete("/transactions/{$cash->id}")->assertSessionHas(
            'message',
            'This transfer is the cash side of the trade Buy 10 NVDA [Broker USD]. Delete the trade '
                .'instead, and its cash goes with it.'
        );
        $this->assertNotNull($cash->fresh());

        $this->delete("/transactions/{$buy->id}")
            ->assertSessionHas('message', 'Trade Buy 10 NVDA [Broker USD] deleted with its cash side: 2 transactions');

        $this->assertNull($cash->fresh());
        $this->assertSame('10000.0000', $this->balance());
    }

    public function test_a_brokerage_naming_no_bank_writes_no_cash(): void
    {
        // Not a state the account form allows, but one data from before the rule can be in.
        $this->broker->meta()->update(['meta' => []]);

        $this->trade('buy', '2026-01-05', '1', '100');

        $this->assertSame(0, Transaction::where('type', 'transfer')->count());
    }

    // ---------------------------------------------------------------------

    /** @return array{0: Account, 1: Account} the bank, then the brokerage settling into it */
    private function brokerage(string $broker, string $bank): array
    {
        $cash = Account::create(['name' => $bank, 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        $security = Account::create(['name' => $broker, 'status' => 'active', 'type' => 'security', 'ccy' => 'USD']);
        $security->meta()->create(['meta' => ['settlement_account_id' => $cash->id]]);

        return [$cash, $security];
    }

    private function trade(string $type, string $date, string $quantity, string $price, ?string $fees = null): Transaction
    {
        $this->post('/transactions', $this->payload($type, $date, $quantity, $price, $fees))
            ->assertSessionHasNoErrors();

        return Transaction::whereIn('type', ['buy', 'sell'])->latest('id')->firstOrFail();
    }

    private function payload(
        string $type,
        string $date,
        string $quantity,
        string $price,
        ?string $fees = null,
        string $status = 'posted'
    ): array {
        return [
            'account_id' => $this->broker->id,
            'date' => $date,
            'type' => $type,
            'description' => "{$type} NVDA",
            'ccy' => 'USD',
            'status' => $status,
            'meta_data' => array_filter(
                ['symbol' => 'NVDA', 'quantity' => $quantity, 'unit_price' => $price, 'fees' => $fees],
                fn ($value) => $value !== null
            ),
        ];
    }

    private function cashOf(Transaction $trade): Transaction
    {
        return Transaction::with('meta')->findOrFail($trade->meta->meta['paired_transaction_id']);
    }

    private function balance(): string
    {
        return AccountBalance::forAccounts(collect([$this->bank->fresh()]))[$this->bank->id];
    }
}
