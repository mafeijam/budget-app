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
            'type' => 'deposit',
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
        $this->assertSame('withdraw', $cash->type);
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
        $this->assertSame(1, Transaction::where('type', 'withdraw')->count(), 'The edit wrote a second cash row.');
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

    public function test_a_trade_edited_into_a_dividend_pays_in_rather_than_out(): void
    {
        // A dividend pays money in, so the cash row changes direction rather than
        // vanishing, and no second row appears.
        $buy = $this->trade('buy', '2026-01-05', '1', '100');

        $this->put("/transactions/{$buy->id}", [
            'account_id' => $this->broker->id,
            'date' => '2026-01-05',
            'type' => 'dividend',
            'description' => 'Dividend',
            'amount' => '3.0000',
            'ccy' => 'USD',
            'meta_data' => ['symbol' => 'NVDA'],
        ])->assertSessionHasNoErrors();

        // The buy's withdrawal became a deposit on the same row: the bank still holds
        // the one cash side beside the salary, not two.
        $this->assertSame(2, Transaction::where('account_id', $this->bank->id)->count());

        $cash = $this->cashOf($buy->fresh());
        $this->assertSame('deposit', $cash->type);
        $this->assertSame('3.0000', $cash->amount);
        $this->assertSame('Dividend NVDA [Broker USD]', $cash->description);
    }

    public function test_the_cash_side_is_locked_to_its_trade(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '10', '100');
        $cash = $this->cashOf($buy);

        $this->put("/transactions/{$cash->id}", [
            'account_id' => $this->bank->id,
            'date' => $cash->date,
            'type' => 'withdraw',
            'description' => $cash->description,
            'amount' => '1.0000',
            'ccy' => 'USD',
        ])->assertSessionHasErrors([
            'amount' => 'This withdraw is the cash side of the trade Buy 10 NVDA [Broker USD], so its '
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
            'This withdraw is the cash side of the trade Buy 10 NVDA [Broker USD]. Delete the trade '
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

        $this->assertSame(0, Transaction::where('type', 'withdraw')->count());
    }

    public function test_a_trade_can_be_recorded_without_its_cash_side(): void
    {
        // The bank is left as it was found: a position back-dated from before it was
        // tracked is recorded as shares, not as money that moved in these accounts.
        $buy = $this->trade('buy', '2026-01-05', '10', '100', '5', noCash: true);

        $this->assertTrue($buy->fresh()->load('meta')->meta->meta['no_cash']);
        $this->assertSame(0, Transaction::where('type', 'withdraw')->count());
        $this->assertSame('10000.0000', $this->balance());
    }

    public function test_ticking_it_afterwards_removes_the_cash_row_it_had_written(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '10', '100');
        $cash = $this->cashOf($buy);

        $this->put("/transactions/{$buy->id}", $this->payload('buy', '2026-01-05', '10', '100', null, 'posted', true))
            ->assertSessionHasNoErrors();

        $this->assertNull($cash->fresh());
        $this->assertSame('10000.0000', $this->balance());
        $this->assertArrayNotHasKey('paired_transaction_id', $buy->fresh()->load('meta')->meta->meta->getArrayCopy());
    }

    public function test_clearing_it_writes_the_cash_side_on_the_trade_date(): void
    {
        $buy = $this->trade('buy', '2026-01-05', '10', '100', noCash: true);

        $this->put("/transactions/{$buy->id}", $this->payload('buy', '2026-01-05', '10', '100'))
            ->assertSessionHasNoErrors();

        $cash = $this->cashOf($buy->fresh());

        $this->assertSame('withdraw', $cash->type);
        $this->assertSame('2026-01-05', $cash->date);
        $this->assertSame('1000.0000', $cash->amount);
        $this->assertSame('9000.0000', $this->balance());
    }

    public function test_the_flag_is_refused_on_an_account_with_no_cash_side(): void
    {
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-01-02',
            'type' => 'deposit',
            'description' => 'Refund',
            'amount' => '40.0000',
            'ccy' => 'USD',
            'meta_data' => ['no_cash' => true],
        ])->assertSessionHasErrors([
            'meta_data.no_cash' => 'Only a buy, a sell or a dividend has a cash side to skip.',
        ]);

        $this->assertSame(0, Transaction::where('type', 'deposit')->where('description', 'Refund')->count());
    }

    public function test_a_dividend_may_opt_out_of_its_cash_side_like_a_trade(): void
    {
        // The back-dating case: a dividend received before the settlement account was
        // tracked records the share but not the bank row it never had.
        $this->post('/transactions', [
            'account_id' => $this->broker->id,
            'date' => '2026-01-05',
            'type' => 'dividend',
            'description' => 'Dividend',
            'amount' => '312.4400',
            'ccy' => 'USD',
            'meta_data' => ['symbol' => 'NVDA', 'no_cash' => true],
        ])->assertSessionHasNoErrors();

        // Only the salary the set-up paid in: no second bank row for the dividend.
        $this->assertSame(1, Transaction::where('account_id', $this->bank->id)->count());

        $dividend = Transaction::where('account_id', $this->broker->id)->firstOrFail();

        $this->assertSame('dividend', $dividend->type);
        $this->assertSame('10000.0000', $this->balance());
    }

    public function test_a_dividend_writes_its_money_into_the_settlement_account(): void
    {
        $dividend = $this->dividend('2026-01-05', '312.4400');

        $cash = $this->cashOf($dividend);

        $this->assertSame($this->bank->id, $cash->account_id);
        $this->assertSame('deposit', $cash->type);
        $this->assertSame('312.4400', $cash->amount);
        $this->assertSame('2026-01-05', $cash->date);
        $this->assertSame('USD', $cash->ccy);
        $this->assertSame('posted', $cash->status);
        $this->assertSame('Dividend NVDA [Broker USD]', $cash->description);
        $this->assertSame($dividend->id, (int) $cash->meta->meta['paired_transaction_id']);

        // And the bank reads it as money in, which is the whole point of writing it.
        $this->assertSame('10312.4400', $this->balance());
    }

    /** A dividend recorded through the form, so it passes every guard a real one does. */
    private function dividend(string $date, string $amount): Transaction
    {
        $this->post('/transactions', [
            'account_id' => $this->broker->id,
            'date' => $date,
            'type' => 'dividend',
            'description' => 'Dividend',
            'amount' => $amount,
            'ccy' => 'USD',
            'meta_data' => ['symbol' => 'NVDA'],
        ])->assertSessionHasNoErrors();

        return Transaction::where('account_id', $this->broker->id)->latest('id')->firstOrFail();
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

    private function trade(
        string $type,
        string $date,
        string $quantity,
        string $price,
        ?string $fees = null,
        bool $noCash = false
    ): Transaction {
        $this->post('/transactions', $this->payload($type, $date, $quantity, $price, $fees, 'posted', $noCash))
            ->assertSessionHasNoErrors();

        return Transaction::whereIn('type', ['buy', 'sell'])->latest('id')->firstOrFail();
    }

    private function payload(
        string $type,
        string $date,
        string $quantity,
        string $price,
        ?string $fees = null,
        string $status = 'posted',
        bool $noCash = false
    ): array {
        return [
            'account_id' => $this->broker->id,
            'date' => $date,
            'type' => $type,
            'description' => "{$type} NVDA",
            'ccy' => 'USD',
            'status' => $status,
            'meta_data' => array_filter(
                [
                    'symbol' => 'NVDA',
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'fees' => $fees,
                    // Absent rather than false, as the form submits it: null is what a
                    // toggle left alone sends, and false is the one value that reads
                    // backwards in TradeCash.
                    'no_cash' => $noCash ?: null,
                ],
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
