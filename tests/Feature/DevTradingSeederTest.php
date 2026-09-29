<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;
use App\Support\AccountBalance;
use App\Support\CashFlow;
use App\Support\Positions;
use Carbon\Carbon;
use Database\Seeders\DevCategorySeeder;
use Database\Seeders\DevHistorySeeder;
use Database\Seeders\DevTradingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * A year of trades on the history bank. The two seeders share that bank, so what is
 * pinned is that re-running either leaves the other's rows whole: a trade whose cash
 * side was deleted still claims a pairing, and nothing reports it.
 */
class DevTradingSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-29 12:00:00');

        $this->seed(DevCategorySeeder::class);
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
        $this->expectExceptionMessage('Refusing to run DevTradingSeeder');

        $this->seed(DevTradingSeeder::class);
    }

    public function test_it_refuses_to_run_before_the_history_bank_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Run the history fixtures first');

        $this->seed(DevTradingSeeder::class);
    }

    public function test_it_leaves_open_positions_a_realised_gain_and_priced_holdings(): void
    {
        $this->seedBoth();

        $positions = Positions::forAccount($this->broker());

        $this->assertSame('2400.00000000', $positions['2800.HK']['quantity']);
        $this->assertSame('100.00000000', $positions['0700.HK']['quantity']);
        $this->assertSame('400.00000000', $positions['0005.HK']['quantity']);
        $this->assertTrue((float) $positions['0700.HK']['realised'] > 0);

        $this->assertSame(0, Positions::valued($this->broker())['totals']['unpriced']);
    }

    public function test_every_trade_has_its_cash_side_and_the_chart_counts_it_as_invested(): void
    {
        $this->seedBoth();

        $this->assertNoTradeWithoutCash();

        $months = collect(collect(CashFlow::lastMonths(today()))->firstWhere('ccy', 'HKD')['months'])->keyBy('month');

        // September: the tracker fund only, 200 at 26.58 plus 15 in fees. July: the Tencent sale takes back more than was bought.
        $this->assertSame('5331.0000', $months['2026-09']['invested']);
        $this->assertTrue(str_starts_with($months['2026-07']['invested'], '-'));

        $bank = Account::where('name', DevHistorySeeder::BANK)->firstOrFail();
        $this->assertStringStartsNotWith('-', AccountBalance::forAccounts(collect([$bank]))[$bank->id]);
    }

    public function test_re_running_either_seeder_keeps_the_other_whole(): void
    {
        $this->seedBoth();
        $count = Transaction::count();

        $this->seed(DevHistorySeeder::class);
        $this->assertSame($count, Transaction::count());
        $this->assertNoTradeWithoutCash();

        $this->seed(DevTradingSeeder::class);
        $this->assertSame($count, Transaction::count());
        $this->assertNoTradeWithoutCash();
    }

    public function test_a_fetched_price_is_never_replaced_by_an_invented_one(): void
    {
        Price::create(['symbol' => '0700.HK', 'date' => '2026-09-28', 'close' => '600', 'ccy' => 'HKD', 'source' => 'yahoo']);

        $this->seedBoth();

        $this->assertSame(['yahoo'], Price::where('symbol', '0700.HK')->pluck('source')->all());
    }

    private function seedBoth(): void
    {
        $this->seed(DevHistorySeeder::class);
        $this->seed(DevTradingSeeder::class);
    }

    private function broker(): Account
    {
        return Account::where('name', DevTradingSeeder::BROKER)->firstOrFail();
    }

    private function assertNoTradeWithoutCash(): void
    {
        foreach (Transaction::with('meta')->where('account_id', $this->broker()->id)->get() as $trade) {
            $cash = Transaction::with('meta')->find($trade->meta->meta['paired_transaction_id'] ?? 0);

            $this->assertNotNull($cash, "[{$trade->description}] on {$trade->date} has lost its cash side.");
            $this->assertSame($trade->id, (int) $cash->meta->meta['paired_transaction_id']);
        }
    }
}
