<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;
use App\Support\CashFlow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * What counts as income, spending and money invested, and above all what counts as none
 * of them: a card repayment and a trade's cash side move money between the user's own
 * accounts, and counting either would misstate a month without anything looking wrong.
 */
class CashFlowTest extends TestCase
{
    use BuildsACard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
        Carbon::setTestNow('2026-09-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_deposit_is_income_and_a_withdrawal_is_spending_under_its_category(): void
    {
        $this->cash('deposit', '2026-09-01', '42000');
        $this->cash('withdraw', '2026-09-02', '15000', $this->category);
        $this->cash('withdraw', '2026-09-03', '500');

        $month = $this->month('HKD', '2026-09');

        $this->assertSame('42000.0000', $month['income']);
        $this->assertSame('15500.0000', $month['spending']);
        $this->assertSame('26500.0000', $month['net']);
        $this->assertSame([
            ['id' => $this->category, 'name' => 'FOOD', 'amount' => '15000.0000'],
            ['id' => null, 'name' => null, 'amount' => '500.0000'],
        ], $month['categories']);
    }

    public function test_a_charge_is_spent_when_due_and_paying_the_card_is_not_spent_again(): void
    {
        $this->charge('2026-08-01', '250');

        $this->post("/accounts/{$this->card->id}/settle", ['due_date' => '2026-09-09', 'owed' => '250.0000'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Transaction::whereIn('type', ['payment', 'withdraw'])->count());

        $this->assertSame('0.0000', $this->month('HKD', '2026-08')['spending']);
        $this->assertSame('250.0000', $this->month('HKD', '2026-09')['spending']);
        $this->assertSame('250.0000', $this->month('HKD', '2026-09')['card_spending']);
        $this->assertSame('0.0000', $this->month('HKD', '2026-09')['cash_spending']);
        $this->assertSame('0.0000', $this->month('HKD', '2026-09')['income']);
    }

    public function test_by_charge_date_a_charge_is_spent_in_the_month_it_was_made(): void
    {
        $this->charge('2026-08-01', '250');

        $months = collect(CashFlow::lastMonths(today(), onDueDate: false)[0]['months'])->keyBy('month');

        $this->assertSame('250.0000', $months['2026-08']['spending']);
        $this->assertSame('0.0000', $months['2026-09']['spending']);
    }

    public function test_a_charge_made_before_the_window_counts_in_the_month_it_falls_due(): void
    {
        // Made in September 2025, before the window opens; due 10 October, inside it.
        $this->charge('2025-09-20', '40');

        $this->assertSame('40.0000', $this->month('HKD', '2025-10')['spending']);
    }

    public function test_a_charge_in_another_currency_counts_at_what_the_card_owes_for_it(): void
    {
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-08-05',
            'type' => 'charge',
            'description' => 'Music',
            'amount' => '10.99',
            'ccy' => 'USD',
            'meta_data' => ['card_amount' => '85.80'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('85.8000', $this->month('HKD', '2026-09')['spending']);
        $this->assertSame(['HKD'], array_column(CashFlow::lastMonths(today()), 'ccy'));
    }

    public function test_a_trade_is_invested_net_of_sales_and_a_dividend_is_income(): void
    {
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $this->brokerRow($broker, 'buy', '2026-09-01', ['symbol' => '0700.HK', 'quantity' => '10', 'unit_price' => '400']);
        $this->brokerRow($broker, 'sell', '2026-09-02', ['symbol' => '0700.HK', 'quantity' => '4', 'unit_price' => '450']);
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-09-03',
            'type' => 'dividend',
            'description' => 'Dividend',
            'amount' => '120',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => '0700.HK', 'brokerage_account_id' => $broker->id],
        ])->assertSessionHasNoErrors();

        $month = $this->month('HKD', '2026-09');

        $this->assertSame('2200.0000', $month['invested']);
        $this->assertSame('120.0000', $month['income']);
        $this->assertSame('120.0000', $month['dividend']);
        $this->assertSame('0.0000', $month['spending']);
    }

    public function test_dividends_are_broken_out_of_income_and_card_charges_out_of_spending(): void
    {
        $this->cash('deposit', '2026-09-01', '1000');
        $this->cash('dividend', '2026-09-02', '30');
        $this->cash('withdraw', '2026-09-03', '200', $this->category);
        $this->charge('2026-08-20', '75');

        $month = $this->month('HKD', '2026-09');

        $this->assertSame('1030.0000', $month['income']);
        $this->assertSame('30.0000', $month['dividend']);
        $this->assertSame('1000.0000', $month['other_income']);
        $this->assertSame('275.0000', $month['spending']);
        $this->assertSame('75.0000', $month['card_spending']);
        $this->assertSame('200.0000', $month['cash_spending']);
        $this->assertSame('30.0000', $this->report('HKD')['totals']['dividend']);
        $this->assertSame('75.0000', $this->report('HKD')['totals']['card_spending']);
    }

    public function test_pending_rows_and_rows_before_the_window_do_not_count(): void
    {
        $this->cash('deposit', '2026-09-01', '100', status: 'pending');
        $this->cash('deposit', '2025-09-30', '100');
        $this->cash('deposit', '2025-10-01', '7');

        $this->assertSame('0.0000', $this->month('HKD', '2026-09')['income']);
        $this->assertSame('7.0000', $this->report('HKD')['totals']['income']);
    }

    public function test_every_month_of_the_window_is_present_oldest_first(): void
    {
        $this->cash('deposit', '2026-09-01', '1');

        $months = array_column($this->report('HKD')['months'], 'month');

        $this->assertCount(12, $months);
        $this->assertSame('2025-10', $months[0]);
        $this->assertSame('2026-09', $months[11]);
        $this->assertSame('2026-02-28', $this->report('HKD')['months'][4]['to']);
    }

    public function test_each_currency_is_its_own_report(): void
    {
        $usd = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);

        $this->cash('deposit', '2026-09-01', '100');
        $this->cash('deposit', '2026-09-01', '30', account: $usd);

        $this->assertSame(['HKD', 'USD'], array_column(CashFlow::lastMonths(today()), 'ccy'));
        $this->assertSame('30.0000', $this->month('USD', '2026-09')['income']);
    }

    public function test_the_combined_report_converts_each_row_at_its_own_days_rate(): void
    {
        $usd = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        $jpy = Account::create(['name' => 'Bank JPY', 'status' => 'active', 'type' => 'cash', 'ccy' => 'JPY']);

        Price::create(['symbol' => 'USDHKD=X', 'date' => '2026-08-01', 'close' => '7.8', 'ccy' => 'HKD', 'source' => 'manual']);
        Price::create(['symbol' => 'USDHKD=X', 'date' => '2026-09-05', 'close' => '8', 'ccy' => 'HKD', 'source' => 'manual']);

        $this->cash('deposit', '2026-09-01', '100');
        $this->cash('deposit', '2026-08-10', '10', account: $usd);
        $this->cash('deposit', '2026-09-10', '10', account: $usd);
        $this->cash('deposit', '2026-09-10', '10', account: $jpy);

        $combined = CashFlow::combined(today());
        $months = collect($combined['report']['months'])->keyBy('month');

        $this->assertSame('HKD', $combined['report']['ccy']);
        $this->assertSame('78.0000', $months['2026-08']['income']);
        $this->assertSame('180.0000', $months['2026-09']['income']);

        // No JPY rate, so its row is left out and named rather than counted at nothing.
        $this->assertSame(['JPY'], $combined['unconverted']);
    }

    public function test_the_page_combines_currencies_unless_one_is_picked(): void
    {
        $usd = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);

        Price::create(['symbol' => 'USDHKD=X', 'date' => '2026-08-01', 'close' => '7.8', 'ccy' => 'HKD', 'source' => 'manual']);

        $this->cash('deposit', '2026-09-01', '100');
        $this->cash('deposit', '2026-09-01', '10', account: $usd);

        $this->get('/cash-flow')->assertInertia(fn (Assert $page) => $page
            ->where('ccy', null)
            ->where('currencies', ['HKD', 'USD'])
            ->has('report', 1)
            ->where('report.0.totals.income', '178.0000')
        );

        $this->get('/cash-flow?ccy=USD')->assertInertia(fn (Assert $page) => $page
            ->where('ccy', 'USD')
            ->has('report', 1)
            ->where('report.0.ccy', 'USD')
            ->where('report.0.totals.income', '10.0000')
        );

        // A currency with no rows is a hand-edited URL, and gets the combined report.
        $this->get('/cash-flow?ccy=JPY')->assertInertia(fn (Assert $page) => $page->where('ccy', null));
    }

    public function test_the_page_carries_the_report(): void
    {
        $this->cash('deposit', '2026-09-01', '100');

        $this->get('/cash-flow')->assertInertia(fn (Assert $page) => $page
            ->component('cash-flow')
            ->where('months', 12)
            ->where('card', 'due')
            ->where('report.0.ccy', 'HKD')
            ->where('report.0.totals.income', '100.0000')
        );

        $this->get('/cash-flow?card=charged')->assertInertia(fn (Assert $page) => $page->where('card', 'charged'));
        $this->get('/cash-flow?card=soon')->assertInertia(fn (Assert $page) => $page->where('card', 'due'));
    }

    private function cash(
        string $type,
        string $date,
        string $amount,
        ?int $category = null,
        string $status = 'posted',
        ?Account $account = null,
    ): void {
        $account ??= $this->bank;

        Transaction::create([
            'account_id' => $account->id,
            'category_id' => $category,
            'date' => $date,
            'type' => $type,
            'description' => ucfirst($type),
            'amount' => $amount,
            'ccy' => $account->ccy,
            'status' => $status,
        ]);
    }

    private function brokerRow(Account $broker, string $type, string $date, array $meta, ?string $amount = null): void
    {
        $this->post('/transactions', [
            'account_id' => $broker->id,
            'date' => $date,
            'type' => $type,
            'description' => ucfirst($type),
            'amount' => $amount,
            'ccy' => 'HKD',
            'meta_data' => $meta,
        ])->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function report(string $ccy): array
    {
        return collect(CashFlow::lastMonths(today()))->firstWhere('ccy', $ccy);
    }

    /** @return array<string, mixed> */
    private function month(string $ccy, string $month): array
    {
        return collect($this->report($ccy)['months'])->firstWhere('month', $month);
    }
}
