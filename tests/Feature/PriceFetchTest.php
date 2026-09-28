<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Price;
use App\Services\YahooFinance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Closing prices from Yahoo into the prices table: the service that reads them, and
 * the command that fetches them for every symbol held. Yahoo is faked throughout.
 */
class PriceFetchTest extends TestCase
{
    use RefreshDatabase;

    private Account $broker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-03-06 18:00', 'Asia/Hong_Kong'));

        $bank = Account::create(['name' => 'Bank HKD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->broker = Account::create(['name' => 'Broker HKD', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->broker->meta()->create(['meta' => ['settlement_account_id' => $bank->id]]);
    }

    // ---------------------------------------------------------------------
    // The service
    // ---------------------------------------------------------------------

    public function test_closes_are_filed_under_the_exchanges_own_day_at_four_places(): void
    {
        // 01:30 UTC on 5 March is 09:30 on 5 March in Hong Kong. The float is what Yahoo
        // actually sends for 436.60.
        $this->fakeChart('0700.HK', 'HKD', 'Asia/Hong_Kong', [
            ['2026-03-05 01:30:00', 436.6000061035156],
            ['2026-03-06 01:30:00', 439.79998779296875],
        ]);

        $quote = app(YahooFinance::class)->closes('0700.HK', '2026-03-01', '2026-03-06');

        $this->assertSame('HKD', $quote['currency']);
        $this->assertSame(['2026-03-05' => '436.6000', '2026-03-06' => '439.8000'], $quote['closes']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/chart/0700.HK')
            && str_starts_with($request->header('User-Agent')[0] ?? '', 'Mozilla/5.0'));
    }

    public function test_a_day_with_no_close_is_skipped_not_stored_as_zero(): void
    {
        $this->fakeChart('0700.HK', 'HKD', 'Asia/Hong_Kong', [
            ['2026-03-05 01:30:00', null],
            ['2026-03-06 01:30:00', 440.0],
        ]);

        $quote = app(YahooFinance::class)->closes('0700.HK', '2026-03-01', '2026-03-06');

        $this->assertSame(['2026-03-06' => '440.0000'], $quote['closes']);
    }

    public function test_a_refusal_throws_rather_than_returning_a_price(): void
    {
        // The old service returned 'NA' and cached it for a week; a thrown failure is
        // reported and the next run tries again.
        Http::fake(['*' => Http::response(['chart' => ['result' => null, 'error' => [
            'description' => 'No data found, symbol may be delisted',
        ]]], 404)]);

        $this->expectException(RuntimeException::class);

        app(YahooFinance::class)->closes('NOPE', '2026-03-01', '2026-03-06');
    }

    // ---------------------------------------------------------------------
    // The command
    // ---------------------------------------------------------------------

    public function test_it_fetches_every_symbol_held_and_no_other(): void
    {
        $this->trade('buy', '0700.HK', '100');
        $this->trade('buy', '0005.HK', '10');
        $this->trade('sell', '0005.HK', '10');

        $this->fakeChart('0700.HK', 'HKD', 'Asia/Hong_Kong', [['2026-03-06 01:30:00', 440.0]]);

        $this->artisan('prices:fetch')->assertSuccessful();

        $this->assertSame('440.0000', Price::where('symbol', '0700.HK')->sole()->close);
        $this->assertSame('yahoo', Price::where('symbol', '0700.HK')->sole()->source);

        // 0005.HK was sold out, so it was never asked for.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '0005.HK'));
    }

    public function test_fetching_a_day_again_updates_it_rather_than_adding_a_second(): void
    {
        $this->trade('buy', '0700.HK', '100');

        // Yahoo revises a close now and then; the second fetch sees the revision.
        Http::fake(['query1.finance.yahoo.com/*' => Http::sequence()
            ->push($this->chart('HKD', 'Asia/Hong_Kong', [['2026-03-06 01:30:00', 440.0]]))
            ->push($this->chart('HKD', 'Asia/Hong_Kong', [['2026-03-06 01:30:00', 441.2]])),
        ]);

        $this->artisan('prices:fetch')->assertSuccessful();
        $this->artisan('prices:fetch')->assertSuccessful();

        $this->assertSame('441.2000', Price::where('symbol', '0700.HK')->sole()->close);
    }

    public function test_a_manual_price_is_never_overwritten_by_a_fetch(): void
    {
        $this->trade('buy', '0700.HK', '100');

        Price::create(['symbol' => '0700.HK', 'date' => '2026-03-06', 'close' => '450.0000', 'ccy' => 'HKD', 'source' => 'manual']);

        $this->fakeChart('0700.HK', 'HKD', 'Asia/Hong_Kong', [['2026-03-06 01:30:00', 440.0]]);
        $this->artisan('prices:fetch')->assertSuccessful();

        $this->assertSame('450.0000', Price::where('symbol', '0700.HK')->sole()->close);
    }

    public function test_a_quote_in_another_currency_than_its_brokerage_is_skipped(): void
    {
        // NVDA held on an HKD brokerage would be valued in HKD at its USD price.
        $this->trade('buy', 'NVDA', '10');

        $this->fakeChart('NVDA', 'USD', 'America/New_York', [['2026-03-05 21:00:00', 900.0]]);

        $this->artisan('prices:fetch')
            ->expectsOutputToContain('NVDA is quoted in USD but held in a HKD brokerage; skipped.')
            ->assertFailed();

        $this->assertSame(0, Price::count());
    }

    // ---------------------------------------------------------------------

    /** @param  list<array{0: string, 1: float|null}>  $days  UTC time and close */
    private function fakeChart(string $symbol, string $ccy, string $timezone, array $days): void
    {
        Http::fake([
            "query1.finance.yahoo.com/v8/finance/chart/{$symbol}*" => Http::response($this->chart($ccy, $timezone, $days)),
        ]);
    }

    /** The shape the chart endpoint answers with, cut to what is read. */
    private function chart(string $ccy, string $timezone, array $days): array
    {
        return ['chart' => [
            'result' => [[
                'meta' => ['currency' => $ccy, 'exchangeTimezoneName' => $timezone],
                'timestamp' => array_map(fn (array $day) => Carbon::parse($day[0], 'UTC')->timestamp, $days),
                'indicators' => ['quote' => [['close' => array_column($days, 1)]]],
            ]],
            'error' => null,
        ]];
    }

    private function trade(string $type, string $symbol, string $quantity): void
    {
        $this->post('/transactions', [
            'account_id' => $this->broker->id,
            'date' => '2026-03-02',
            'type' => $type,
            'description' => "{$type} {$symbol}",
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => $symbol, 'quantity' => $quantity, 'unit_price' => '10'],
        ])->assertSessionHasNoErrors();
    }
}
