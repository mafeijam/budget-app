<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;
use App\Support\AccountBalance;
use App\Support\CashFlow;
use App\Support\Positions;
use Carbon\Carbon;
use Database\Seeders\DevUsdTradingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * A year of US trading on a bank of its own. What is pinned is what makes it a second
 * currency rather than more of the first: its totals stay USD, and its opening deposit
 * sits outside the chart rather than as a month of invented income.
 */
class DevUsdTradingSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_refuses_a_database_that_does_not_look_like_a_test_one(): void
    {
        Config::set('database.connections.mysql.database', 'budget');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run DevUsdTradingSeeder');

        $this->seed(DevUsdTradingSeeder::class);
    }

    public function test_it_leaves_open_positions_a_trim_at_a_gain_and_a_sell_out_at_a_loss(): void
    {
        $this->seed(DevUsdTradingSeeder::class);

        $positions = Positions::forAccount($this->broker());

        $this->assertSame('60.00000000', $positions['VOO']['quantity']);
        $this->assertSame('30.00000000', $positions['NVDA']['quantity']);
        $this->assertTrue((float) $positions['NVDA']['realised'] > 0);
        $this->assertFalse($positions['AAPL']['open']);
        $this->assertStringStartsWith('-', $positions['AAPL']['realised']);

        $this->assertSame(0, Positions::valued($this->broker())['totals']['unpriced']);
    }

    public function test_the_chart_shows_dividends_as_income_and_not_the_opening_deposit(): void
    {
        $this->seed(DevUsdTradingSeeder::class);

        $usd = collect(CashFlow::lastMonths(today()))->firstWhere('ccy', 'USD');

        $this->assertSame('100.3000', $usd['totals']['income']);
        $this->assertSame('0.0000', $usd['totals']['spending']);
        $this->assertTrue((float) $usd['totals']['invested'] > 0);

        $bank = Account::where('name', DevUsdTradingSeeder::BANK)->firstOrFail();
        $this->assertStringStartsNotWith('-', AccountBalance::forAccounts(collect([$bank]))[$bank->id]);
    }

    public function test_the_all_view_totals_it_in_usd(): void
    {
        $this->seed(DevUsdTradingSeeder::class);

        $this->get('/positions')->assertInertia(fn (Assert $page) => $page
            ->where('totals', fn ($totals) => $totals->pluck('ccy')->all() === ['USD'])
        );
    }

    public function test_re_running_replaces_the_history_rather_than_doubling_it(): void
    {
        $this->seed(DevUsdTradingSeeder::class);
        $count = Transaction::count();

        $this->seed(DevUsdTradingSeeder::class);

        $this->assertSame($count, Transaction::count());
    }

    public function test_a_fetched_price_is_never_replaced_by_an_invented_one(): void
    {
        Price::create(['symbol' => 'NVDA', 'date' => '2026-09-28', 'close' => '180', 'ccy' => 'USD', 'source' => 'yahoo']);

        $this->seed(DevUsdTradingSeeder::class);

        $this->assertSame(['yahoo'], Price::where('symbol', 'NVDA')->pluck('source')->all());
    }

    private function broker(): Account
    {
        return Account::where('name', DevUsdTradingSeeder::BROKER)->firstOrFail();
    }
}
