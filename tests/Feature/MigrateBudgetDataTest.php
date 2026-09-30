<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\CashFlow;
use App\Support\Legacy\BudgetSource;
use App\Support\Positions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * A trade the old ledger described in words, and the bank row that description came
 * from, have to arrive paired.
 *
 * The pairing is the only thing that tells CashFlow which rows are a trade's cash side,
 * because a brokerage holds no balance and its own row is filtered out before anything
 * is classified. Unpaired, a buy reads as spending and a sell as income, and the month
 * shows the same money twice with Invested at zero -- with nothing on the page wrong.
 *
 * The old database is a BudgetSource subclass rather than a second connection, so the
 * command's own guard -- which refuses to run when the two databases are one -- still
 * sees two names, and the fixtures are an array rather than a schema.
 */
class MigrateBudgetDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_trade_the_ledger_described_is_paired_with_the_bank_row_it_came_from(): void
    {
        $this->migrate();

        $buy = $this->trade('ADVANCE Securities', '2026-09-01');
        $cash = $buy->meta->meta['paired_transaction_id'] ?? null;

        $this->assertNotNull($cash, 'A trade from the cash ledger must name the row that moved the money.');
        $this->assertArrayNotHasKey('no_cash', $buy->meta->meta->getArrayCopy());

        $row = Transaction::with('meta')->findOrFail($cash);

        $this->assertSame('ADVANCE', $row->account->name);
        $this->assertSame((int) $buy->id, (int) $row->meta->meta['paired_transaction_id']);

        // The bank row stays as the ledger recorded it, rather than being rewritten from
        // the trade: the figure is what the bank reported, and openingBalances() has
        // already reconciled the account's closing balance against it.
        $this->assertSame('BUY 3,000 SHARES 700 @ 10.32', $row->description);
        $this->assertSame('30960.0000', $row->amount);
    }

    public function test_a_sell_is_paired_as_well_so_its_proceeds_take_back_the_buy(): void
    {
        $this->migrate();

        $sell = $this->trade('ADVANCE Securities', '2026-09-02');

        $this->assertSame('sell', $sell->type);
        $this->assertNotNull($sell->meta->meta['paired_transaction_id'] ?? null);
        $this->assertArrayNotHasKey('no_cash', $sell->meta->meta->getArrayCopy());
    }

    public function test_the_bank_rows_read_as_money_invested_rather_than_spent_and_earned(): void
    {
        $this->migrate();

        $month = $this->month('2026-09');

        // 3,000 + 1,000 bought, 1,000 sold: 30,960 + 60,000 - 11,000.
        $this->assertSame('79960.0000', $month['invested']);

        // The buy used to land in spending and the sell in income, so this pair of
        // assertions is the whole bug: neither figure moves, and Invested is not zero.
        $this->assertSame('0.0000', $month['income']);
        $this->assertSame('50.0000', $month['spending']);
        $this->assertSame('50.0000', $month['cash_spending']);
    }

    public function test_the_paired_rows_are_still_the_only_ones_written_for_a_trade(): void
    {
        $this->migrate();

        // Four cash rows and four trades, the fourth being the backdated holding, and no
        // second leg: no_cash held off TradeCash while the row was written, and the
        // pairing took its place afterwards.
        $this->assertSame(4, Transaction::whereIn('type', ['withdraw', 'deposit'])->count());
        $this->assertSame(4, Transaction::whereIn('type', ['buy', 'sell'])->count());
    }

    public function test_a_buy_backdated_from_a_holding_has_no_cash_side_to_pair_with(): void
    {
        $this->migrate();

        $backdated = $this->trade('ADVANCE Securities', '2026-07-20');

        $this->assertTrue($backdated->meta->meta['no_cash']);
        $this->assertArrayNotHasKey('paired_transaction_id', $backdated->meta->meta->getArrayCopy());

        // The money never moved in the old ledger, so the position is replayed from the
        // holding's own quantity and cost and reaches no balance. 3,000 from the cash
        // ledger's own buy, plus the 500 this holding adds.
        $positions = collect(Positions::forAccount($backdated->account))->keyBy('symbol');

        // 3,000 bought less the 1,000 sold, plus the 500 this holding adds.
        $this->assertSame('2500.00000000', $positions['0700.HK']['quantity']);
    }

    public function test_a_trade_on_an_account_no_brokerage_settles_into_is_reported_and_its_cash_left_alone(): void
    {
        $reported = $this->migrate([
            ['id' => 1, 'acct_id' => 3, 'date' => '2026-09-05', 'description' => 'BUY 500 SHARES 941 @ 300.00', 'type' => 'withdraw', 'amount' => '150000', 'balance' => '-150000'],
        ]);

        $this->assertStringContainsString('no brokerage settles into', $reported);

        $this->assertSame(0, Transaction::whereIn('type', ['buy', 'sell'])->count());
        $this->assertSame('150000.0000', Transaction::where('type', 'withdraw')->value('amount'));
    }

    /**
     * The old ledger's rows, with $cash overriding the cash_transactions, and what the
     * command reported about them.
     *
     * The running `balance` starts at zero on every account, so the rows account for the
     * whole closing balance and no opening balance is invented on top of them -- which
     * would otherwise show up as income in the month under test.
     *
     * @param  list<array<string, mixed>>  $cash
     */
    private function migrate(array $cash = []): string
    {
        $this->instance(BudgetSource::class, new class($cash) extends BudgetSource
        {
            /** @param list<array<string, mixed>> $cash */
            public function __construct(private array $cash) {}

            public function accounts(): array
            {
                return [
                    ['id' => 1, 'name' => 'ADVANCE'],
                    ['id' => 2, 'name' => 'FUTU'],
                    ['id' => 3, 'name' => 'SAVING'],
                ];
            }

            public function cards(): array
            {
                return [];
            }

            public function categories(): array
            {
                return [];
            }

            public function cardTransactions(): array
            {
                return [];
            }

            public function cashTransactions(): array
            {
                return $this->cash ?: [
                    ['id' => 1, 'acct_id' => 1, 'date' => '2026-09-01', 'description' => 'BUY 3,000 SHARES 700 @ 10.32', 'type' => 'withdraw', 'amount' => '30960', 'balance' => '-30960'],
                    ['id' => 2, 'acct_id' => 1, 'date' => '2026-09-02', 'description' => 'SELL 1,000 SHARES 700 @ 11.00', 'type' => 'deposit', 'amount' => '11000', 'balance' => '-19960'],
                    ['id' => 3, 'acct_id' => 2, 'date' => '2026-09-03', 'description' => 'BUY 1,000 SHARES 5 @ 60.00', 'type' => 'withdraw', 'amount' => '60000', 'balance' => '-60000'],
                    ['id' => 4, 'acct_id' => 3, 'date' => '2026-09-04', 'description' => 'Coffee', 'type' => 'withdraw', 'amount' => '50', 'balance' => '-50'],
                ];
            }

            public function stockHoldings(): array
            {
                // Six weeks before the cash ledger's earliest trade, so it is too far
                // outside TRADE_DATE_SLACK to be read as the same purchase.
                return [
                    ['id' => 1, 'code' => '700', 'qty' => '500', 'cost' => '9.00', 'date' => '2026-07-20', 'sold' => null, 'group_num' => 1],
                ];
            }

            public function recurringPayments(): array
            {
                return [];
            }

            public function latestActivity(): ?string
            {
                return '2026-09-04';
            }
        });

        $output = new BufferedOutput;
        $code = Artisan::call('budget:migrate', ['--commit' => true], $output);

        $reported = $output->fetch();

        $this->assertSame(0, $code, $reported);

        return $reported;
    }

    private function trade(string $brokerage, string $date): Transaction
    {
        return Transaction::with('meta', 'account')
            ->whereHas('account', fn ($q) => $q->where('name', $brokerage))
            ->whereIn('type', ['buy', 'sell'])
            ->where('date', $date)
            ->firstOrFail();
    }

    /** @return array<string, string> */
    private function month(string $key): array
    {
        return collect(CashFlow::lastMonths(today())[0]['months'])->keyBy('month')[$key];
    }
}
