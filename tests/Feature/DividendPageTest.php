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
                ['year' => 2026, 'total' => '0.0000'],
                ['year' => 2025, 'total' => '150.0000'],
                ['year' => 2024, 'total' => '40.0000'],
            ])
        );
    }

    public function test_a_year_with_no_dividends_is_this_year_rather_than_an_error(): void
    {
        $this->get('/dividends?year=1999')->assertInertia(fn (Assert $page) => $page
            ->where('year', 2026)
            ->where('symbols', [])
        );
    }

    private function dividend(string $date, string $symbol, string $amount): void
    {
        $this->post('/transactions', [
            'account_id' => $this->bank->id, 'date' => $date, 'type' => 'dividend', 'description' => 'Dividend',
            'amount' => $amount, 'ccy' => 'HKD', 'status' => 'posted',
            'meta_data' => ['symbol' => $symbol, 'brokerage_account_id' => $this->broker->id],
        ])->assertSessionHasNoErrors();
    }
}
