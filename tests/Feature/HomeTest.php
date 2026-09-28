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
            'type' => 'income',
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
            'type' => 'income',
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
}
