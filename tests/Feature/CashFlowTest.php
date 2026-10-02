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

    public function test_money_moved_between_cash_accounts_is_neither_income_nor_spending(): void
    {
        $savings = Account::create(['name' => 'Savings', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        // A transfer, entered as it is here: two plain rows, nothing pairing them.
        $this->cash('withdraw', '2026-09-01', '5000', null, 'posted', $this->bank);
        $this->cash('deposit', '2026-09-01', '5000', null, 'posted', $savings);
        // An exchange out and straight back in on one account, the same.
        $this->cash('withdraw', '2026-09-02', '800', null, 'posted', $savings);
        $this->cash('deposit', '2026-09-02', '800', null, 'posted', $savings);
        // Not one: another day, and another amount.
        $this->cash('withdraw', '2026-09-03', '5000', null, 'posted', $this->bank);
        $this->cash('deposit', '2026-09-04', '300', null, 'posted', $savings);

        $month = $this->month('HKD', '2026-09');

        $this->assertSame('300.0000', $month['income']);
        $this->assertSame('5000.0000', $month['spending']);

        // The spending filter states the rule in SQL, so the list a tile links to is the rows
        // the tile added up.
        $listed = null;
        $this->get('/transactions?filter[spending]=1&per_page=50')->assertInertia(function (Assert $page) use (&$listed) {
            $listed = collect($page->toArray()['props']['data']['data'])->map(fn (array $row) => [$row['date'], $row['amount']])->all();

            return $page;
        });

        $this->assertSame([['2026-09-03', '5000.0000']], $listed);
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

    public function test_the_window_travels_back_a_month_at_a_time(): void
    {
        // A ledger begun mid-2016, so its first whole year is 2017 and the window may begin
        // there: 105 months before the 2025-10 this September's window starts.
        $this->cash('deposit', '2016-06-15', '1');
        $this->cash('deposit', '2025-09-01', '70');
        $this->cash('deposit', '2026-09-01', '30');

        $this->get('/cash-flow?since=2025-09')->assertInertia(fn (Assert $page) => $page
            ->where('since', '2025-09')
            ->where('back', 1)
            ->where('furthest', 105)
            // The caption names since and the chart opens on it, so they cannot drift apart.
            ->where('views.due.all.report.0.months.0.month', '2025-09')
            ->where('views.due.all.report.0.months.11.month', '2026-08')
            // Last September's row is in the window that moved; this month's is in neither.
            ->where('views.due.all.report.0.totals.income', '70.0000')
        );
    }

    public function test_the_window_may_not_begin_before_the_first_whole_year(): void
    {
        $this->cash('deposit', '2016-06-15', '1');
        $this->cash('deposit', '2017-06-15', '40');
        $this->cash('deposit', '2026-09-01', '100');

        // 2016 is seven months of a ledger opened on balances brought into it, so a window
        // may not begin there: it would put a part year at one end against twelve at the other.
        $this->get('/cash-flow?since=2016-12')->assertInertia(fn (Assert $page) => $page
            ->where('since', '2025-10')
            ->where('back', 0)
            ->where('views.due.all.report.0.totals.income', '100.0000')
        );

        $this->get('/cash-flow?since=2017-01')->assertInertia(fn (Assert $page) => $page
            ->where('since', '2017-01')
            ->where('back', 105)
            ->where('views.due.all.report.0.months.0.month', '2017-01')
            ->where('views.due.all.report.0.months.11.month', '2017-12')
            // Only 2017's own row: not the one behind the window that was refused, and not the
            // one eleven years past it. A window that quietly read this month's rows instead
            // would pass every other assertion here.
            ->where('views.due.all.report.0.totals.income', '40.0000')
        );
    }

    public function test_a_month_that_is_not_one_is_the_current_window(): void
    {
        $this->cash('deposit', '2016-06-15', '1');
        $this->cash('deposit', '2026-09-01', '100');

        // Both ends of the range refused the same way as a middle one: a month that does not
        // exist, and one the window cannot reach yet.
        foreach (['2026-13', '2026-00', '2027-01', 'abc', ''] as $asked) {
            $this->get("/cash-flow?since={$asked}")->assertInertia(fn (Assert $page) => $page
                ->where('since', '2025-10')
                ->where('back', 0)
            );
        }
    }

    public function test_a_ledger_under_a_year_old_has_nowhere_to_travel_to(): void
    {
        $this->cash('deposit', '2026-09-01', '100');

        // The ledger began this year, so its start is the day it began and no window of this
        // length fits behind it yet -- which is furthest 0, and the page offering no window control.
        $this->get('/cash-flow')->assertInertia(fn (Assert $page) => $page
            ->where('furthest', 0)
            ->where('since', '2025-10')
            ->where('back', 0)
        );
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

    public function test_the_page_carries_a_view_for_each_currency_and_each_card_setting(): void
    {
        $usd = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);

        Price::create(['symbol' => 'USDHKD=X', 'date' => '2026-08-01', 'close' => '7.8', 'ccy' => 'HKD', 'source' => 'manual']);

        $this->cash('deposit', '2026-09-01', '100');
        $this->cash('deposit', '2026-09-01', '10', account: $usd);

        // The choices are the browser's to keep, so the server sends them all and no query
        // string is read: the report for no currency picked, for each, and each way a card
        // is counted.
        $this->get('/cash-flow?ccy=USD&card=charged')->assertInertia(fn (Assert $page) => $page
            ->where('currencies', ['HKD', 'USD'])
            ->has('views.due', 3)
            ->has('views.charged', 3)
            ->has('views.due.all.report', 1)
            ->where('views.due.all.report.0.totals.income', '178.0000')
            ->where('views.due.USD.report.0.ccy', 'USD')
            ->where('views.due.USD.report.0.totals.income', '10.0000')
            ->where('views.charged.HKD.report.0.totals.income', '100.0000')
            ->missing('ccy')
            ->missing('card')
        );
    }

    public function test_a_tiles_transactions_are_the_rows_it_added_up(): void
    {
        $usd = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        Price::create(['symbol' => 'USDHKD=X', 'date' => '2026-08-01', 'close' => '7.8', 'ccy' => 'HKD', 'source' => 'manual']);

        $this->cash('withdraw', '2026-09-02', '15000', $this->category);
        $this->cash('withdraw', '2026-09-03', '10', $this->category, account: $usd);
        $this->cash('withdraw', '2026-09-04', '500');
        // Another month, another category, and a pending row: none are in this tile.
        $this->cash('withdraw', '2026-08-30', '70', $this->category);
        $this->cash('withdraw', '2026-09-05', '80', $this->category, 'pending');
        // A card paid is not spent twice, and a buy's withdrawal is invested, not spent.
        $this->charge('2026-08-01', '250');
        $this->post("/accounts/{$this->card->id}/settle", ['due_date' => '2026-09-09', 'owed' => '250.0000'])
            ->assertSessionHasNoErrors();
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);
        $this->brokerRow($broker, 'buy', '2026-09-06', ['symbol' => '0700.HK', 'quantity' => '10', 'unit_price' => '400']);

        // The tile as the page shows it with no currency picked: everything in HKD.
        $combined = collect(CashFlow::combined(today())['report']['months'])->firstWhere('month', '2026-09');
        $tile = fn (?int $category) => collect(collect($combined['categories'])->firstWhere('id', $category));
        $window = ['from' => '2026-09-01', 'to' => '2026-09-30'];

        // The base currency: the dollars at 7.8, the figure the tile shows.
        $food = $this->getJson('/cash-flow/transactions?'.http_build_query($window + ['category' => $this->category]))->assertOk();

        // The two withdrawals and the card charge due in September, largest first, by what
        // each is in HKD: the 10 dollars are 78.
        $this->assertSame(3, $food['count']);
        $this->assertSame(['2026-09-02', '2026-08-01', '2026-09-03'], array_column($food['rows'], 'date'));
        $this->assertSame('78.0000', $food['rows'][2]['base']);
        $this->assertSame('250.0000', $food['rows'][1]['amount']);
        $this->assertSame($tile($this->category)['amount'], $food['total']);
        $this->assertSame('HKD', $food['ccy']);

        // No category: the cash withdrawal and the card settled, and not the buy.
        $none = $this->getJson('/cash-flow/transactions?'.http_build_query($window + ['category' => 'none']))->assertOk();

        $this->assertSame($tile(null)['amount'], $none['total']);
        $this->assertSame('500.0000', collect($none['rows'])->firstWhere('description', 'Withdraw')['amount']);
        $this->assertNull(collect($none['rows'])->first(fn ($row) => str_starts_with($row['description'], 'Buy')));

        // One currency, in its own money.
        $only = $this->getJson('/cash-flow/transactions?'.http_build_query($window + ['category' => $this->category, 'ccy' => 'USD']))->assertOk();

        $this->assertSame(1, $only['count']);
        $this->assertSame('10.0000', $only['total']);
        $this->assertSame('USD', $only['ccy']);
    }

    public function test_a_tiles_transactions_read_the_window_as_the_card_setting_does(): void
    {
        $this->charge('2026-08-20', '250');
        $window = ['from' => '2026-09-01', 'to' => '2026-09-30', 'category' => $this->category];

        // Charged in August, due in September: in September's tile by due date, and not by
        // the day it was made.
        $this->getJson('/cash-flow/transactions?'.http_build_query($window))->assertJsonPath('count', 1);
        $this->getJson('/cash-flow/transactions?'.http_build_query($window + ['card' => 'charged']))->assertJsonPath('count', 0);
    }

    public function test_a_tiles_transactions_refuse_a_malformed_request(): void
    {
        $this->getJson('/cash-flow/transactions?from=x&to=2026-09-30&category=none')->assertStatus(422);
        $this->getJson('/cash-flow/transactions?from=2026-09-01&to=2026-09-30&category=abc')->assertStatus(422);
        $this->getJson('/cash-flow/transactions?from=2026-09-01&to=2026-09-30&category=none&ccy=EUR')->assertStatus(422);
    }

    public function test_the_page_carries_the_report(): void
    {
        $this->cash('deposit', '2026-09-01', '100');

        $this->get('/cash-flow')->assertInertia(fn (Assert $page) => $page
            ->component('cash-flow')
            ->where('months', 12)
            ->where('since', '2025-10')
            ->where('views.due.all.report.0.ccy', 'HKD')
            ->where('views.due.all.report.0.totals.income', '100.0000')
        );
    }

    public function test_by_due_date_and_by_charge_date_are_each_a_view_of_their_own(): void
    {
        $this->charge('2026-08-20', '250');

        $this->get('/cash-flow')->assertInertia(fn (Assert $page) => $page
            ->where('views.due.all.report.0.months', fn ($months) => collect($months)->firstWhere('month', '2026-09')['spending'] === '250.0000')
            ->where('views.charged.all.report.0.months', fn ($months) => collect($months)->firstWhere('month', '2026-08')['spending'] === '250.0000')
        );
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
