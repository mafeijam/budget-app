<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Price;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * The home page: what is held in cash, and what is owed on the cards.
 */
class HomeTest extends TestCase
{
    use BuildsACard, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
    }

    public function test_it_shows_each_cash_account_with_its_balance(): void
    {
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-01-01',
            'type' => 'deposit',
            'description' => 'Salary',
            'amount' => '30000.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('index')
            ->has('cash', 1)
            ->where('cash.0.name', 'Bank')
            ->where('cash.0.balance', '30000.0000')
        );
    }

    public function test_a_closed_cash_account_shows_only_while_it_holds_money(): void
    {
        // Hidden with money in it, the total would be a figure nobody can account for.
        Account::create(['name' => 'Old empty', 'status' => 'inactive', 'type' => 'cash', 'ccy' => 'HKD']);
        $holding = Account::create(['name' => 'Old holding', 'status' => 'inactive', 'type' => 'cash', 'ccy' => 'HKD']);

        $this->post('/transactions', [
            'account_id' => $holding->id,
            'date' => '2026-01-01',
            'type' => 'deposit',
            'description' => 'Left over',
            'amount' => '5.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('cash', fn ($rows) => $rows->pluck('name')->all() === ['Bank', 'Old holding'])
        );
    }

    public function test_it_lists_the_statements_still_owing_soonest_first(): void
    {
        $this->travelTo(Carbon::parse('2026-02-01 12:00', 'Asia/Hong_Kong'));

        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-02-10', '80.0000');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('statements', 2)
            ->where('statements.0.due_date', self::PERIOD)
            ->where('statements.0.owed', '120.0000')
            ->where('statements.0.days_until_due', 8)
            ->where('statements.0.card.name', 'Card')
            ->where('statements.1.owed', '80.0000')
        );
    }

    public function test_a_settled_statement_is_not_listed(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('statements', 0)
        );
    }

    public function test_a_brokerage_is_shown_at_market_value_beside_the_cash_not_in_it(): void
    {
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $this->post('/transactions', [
            'account_id' => $broker->id,
            'date' => '2026-01-05',
            'type' => 'buy',
            'description' => 'Buy 0700.HK',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '400'],
        ])->assertSessionHasNoErrors();

        Price::create(['symbol' => '0700.HK', 'date' => today()->toDateString(), 'close' => '440.0000', 'ccy' => 'HKD', 'source' => 'yahoo']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('cash', fn ($rows) => $rows->pluck('name')->all() === ['Bank'])
            ->has('brokerages', 1)
            ->where('brokerages.0.name', 'Broker')
            ->where('brokerages.0.market_value', '44000.0000')
            ->where('brokerages.0.unrealised', '4000.0000')
            ->where('brokerages.0.open', 1)
            ->where('brokerages.0.unpriced', 0)
        );
    }

    public function test_a_closed_brokerage_holding_nothing_is_not_shown(): void
    {
        Account::create(['name' => 'Old broker', 'status' => 'inactive', 'type' => 'security', 'ccy' => 'HKD']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('brokerages', 0));
    }

    public function test_the_headline_is_the_net_worth_pages_figures_for_today(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 12:00', 'Asia/Hong_Kong'));

        $this->deposit('2026-01-10', '1000.0000');
        $this->deposit('2026-02-10', '500.0000');
        $this->charge('2026-02-12', '200.0000');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('headline.net_worth', '1300.0000')
            ->where('headline.cash', '1500.0000')
            ->where('headline.cards', '-200.0000')
            ->where('headline.last_month', '1000.0000')
            ->where('headline.change', '300.0000')
            ->where('headline.owed', '200.0000')
            // Deferred, so it is not in the first response at all. Asserted here because
            // a `->where('trend', ...)` against a prop that never arrives passes against
            // nothing, which is the same way a broken chart hides.
            ->missing('trend')
        );

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            // January's end, then today's: the history starts at the first transaction.
            ->loadDeferredProps('default', fn (Assert $deferred) => $deferred
                ->where('trend', fn ($points) => $points->pluck('date')->all() === ['2026-01-31', '2026-02-15'])
                ->where('trend.1.cards', '-200.0000')
            )
        );
    }

    public function test_it_is_all_clear_with_nothing_to_deal_with(): void
    {
        $this->deposit('2026-01-10', '1000.0000');

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('attention', []));
    }

    public function test_an_overdue_statement_a_negative_account_and_due_pending_rows_need_attention(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 12:00', 'Asia/Hong_Kong'));

        $this->charge('2026-01-01', '1234.5000');

        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-02-20',
            'type' => 'withdraw',
            'description' => 'Overdrawn',
            'amount' => '50.0000',
            'ccy' => 'HKD',
            'category_id' => $this->category,
        ])->assertSessionHasNoErrors();

        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-02-25',
            'type' => 'deposit',
            'description' => 'Refund',
            'amount' => '10.0000',
            'ccy' => 'HKD',
            'status' => 'pending',
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('attention.0.level', 'negative')
            ->where('attention.0.message', 'Card: 1,234.50 HKD was due on '.self::PERIOD)
            ->where('attention.0.link.data.filter.due_date', self::PERIOD)
            ->where('attention.1.message', 'Bank is below zero: -50.00 HKD')
            ->where('attention', fn ($items) => $items->contains('message', '1 pending transaction is due and not posted yet'))
        );
    }

    public function test_stale_prices_need_attention_only_while_shares_are_held(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 12:00', 'Asia/Hong_Kong'));
        $this->deposit('2026-01-02', '100000.0000');

        $stale = fn ($items) => $items->contains(fn ($item) => str_starts_with($item['message'], 'Prices'));

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('attention', fn ($items) => ! $stale($items)));

        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $this->post('/transactions', [
            'account_id' => $broker->id,
            'date' => '2026-01-05',
            'type' => 'buy',
            'description' => 'Buy 0700.HK',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => '0700.HK', 'quantity' => '10', 'unit_price' => '400'],
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('attention', fn ($items) => $items->contains('message', 'Prices have never been fetched'))
        );
    }

    private function deposit(string $date, string $amount): void
    {
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => $date,
            'type' => 'deposit',
            'description' => 'Salary',
            'amount' => $amount,
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();
    }
}
