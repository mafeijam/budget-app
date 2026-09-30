<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\CashFlow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A migrated trade and its bank row, recorded apart: until they are linked the bank row
 * is a month's spending, and after it is money invested with the balance untouched.
 */
class LinkTradeCashTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $broker;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 12:00:00');

        $this->bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $trade = $this->trade('buy', '2026-07-02', '67736.3600');
        $this->row('withdraw', '2026-07-02', '67736.3600');

        $this->artisan('trades:link-cash')->expectsOutputToContain('1 to link')->assertSuccessful();

        $this->assertTrue($trade->meta->fresh()->meta['no_cash']);
        $this->assertSame('67736.3600', $this->month('2026-07')['spending']);
    }

    public function test_apply_links_both_ways_and_the_cash_counts_as_invested(): void
    {
        $trade = $this->trade('buy', '2026-07-02', '67736.3600');
        $cash = $this->row('withdraw', '2026-07-02', '67736.3600');
        $this->row('deposit', '2026-07-02', '302');

        $this->artisan('trades:link-cash --apply')->assertSuccessful();

        $bag = $trade->meta->fresh()->meta;
        $this->assertArrayNotHasKey('no_cash', $bag->getArrayCopy());
        $this->assertSame($cash->id, $bag['paired_transaction_id']);
        $this->assertSame($trade->id, $cash->fresh()->meta->meta['paired_transaction_id']);

        $month = $this->month('2026-07');
        $this->assertSame('67736.3600', $month['invested']);
        $this->assertSame('0.0000', $month['spending']);
        $this->assertSame('302.0000', $month['income']);
    }

    public function test_a_sell_links_to_a_deposit_and_a_mismatch_is_left_alone(): void
    {
        $sell = $this->trade('sell', '2026-06-14', '6411.5000');
        $this->row('deposit', '2026-06-14', '6411.5000');
        $odd = $this->trade('buy', '2026-05-10', '2691.0000');
        $this->row('withdraw', '2026-05-10', '2690.0000');

        $this->artisan('trades:link-cash --apply')
            ->expectsOutputToContain('1 to link, 1 without a match')
            ->assertSuccessful();

        $this->assertNotNull($sell->meta->fresh()->meta['paired_transaction_id'] ?? null);
        $this->assertTrue($odd->meta->fresh()->meta['no_cash']);
    }

    public function test_two_trades_on_one_day_do_not_claim_the_same_bank_row(): void
    {
        $first = $this->trade('buy', '2026-04-05', '5000.0000');
        $second = $this->trade('buy', '2026-04-05', '5000.0000');
        $this->row('withdraw', '2026-04-05', '5000.0000');

        $this->artisan('trades:link-cash --apply')->expectsOutputToContain('1 to link, 1 without a match');

        $this->assertNotNull($first->meta->fresh()->meta['paired_transaction_id'] ?? null);
        $this->assertTrue($second->meta->fresh()->meta['no_cash']);
    }

    public function test_a_second_run_finds_nothing_to_do(): void
    {
        $this->trade('buy', '2026-07-02', '100.0000');
        $this->row('withdraw', '2026-07-02', '100.0000');

        $this->artisan('trades:link-cash --apply')->assertSuccessful();
        $this->artisan('trades:link-cash --apply')->expectsOutputToContain('0 unlinked trades')->assertSuccessful();
    }

    private function trade(string $type, string $date, string $amount): Transaction
    {
        $trade = Transaction::create([
            'account_id' => $this->broker->id, 'date' => $date, 'type' => $type,
            'description' => ucfirst($type), 'amount' => $amount, 'ccy' => 'HKD', 'status' => 'posted',
        ]);
        $trade->meta()->create(['meta' => ['symbol' => '3190.HK', 'quantity' => '100', 'unit_price' => '1', 'no_cash' => true]]);

        return $trade;
    }

    private function row(string $type, string $date, string $amount): Transaction
    {
        return Transaction::create([
            'account_id' => $this->bank->id, 'date' => $date, 'type' => $type,
            'description' => 'Legacy', 'amount' => $amount, 'ccy' => 'HKD', 'status' => 'posted',
        ]);
    }

    /** @return array<string, mixed> */
    private function month(string $month): array
    {
        return collect(CashFlow::lastMonths(today())[0]['months'])->firstWhere('month', $month);
    }
}
