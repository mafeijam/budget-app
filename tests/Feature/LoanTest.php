<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\NetWorth;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A loan is its tagged rows: the money borrowed sits in the cash, so net worth subtracts what
 * is still owed until the last instalment, and only the principal of each repayment counts.
 */
class LoanTest extends TestCase
{
    use RefreshDatabase;

    private Account $saving;

    private Account $card;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-15 12:00:00');

        $this->saving = Account::create(['name' => 'SAVING', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->card = Account::create(['name' => 'MASTER', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_tax_loan_is_owed_until_its_principal_is_repaid(): void
    {
        $this->taxLoan();

        $this->artisan('loans:tag')->assertSuccessful();
        $this->assertSame('0.0000', (new NetWorth)->on('2019-01-31')['loans'], 'A dry run writes nothing.');

        $this->artisan('loans:tag', ['--apply' => true])->assertSuccessful();

        // 270,000 borrowed and one 11,250 repaid; the other 324 of the payment was interest.
        $february = (new NetWorth)->on('2019-02-28');
        $this->assertSame('258750.0000', $february['loans']);
        $this->assertSame('258426.0000', $february['cash']);
        $this->assertSame('-324.0000', $february['net_worth']);
        $this->assertSame([['name' => 'HSBC TAX LOAN', 'ccy' => 'HKD', 'owed' => '258750.0000', 'base' => '258750.0000']], $february['loan_rows']);

        $this->assertSame('0.0000', (new NetWorth)->on('2021-02-28')['loans']);

        // Found by description, so a second run, or a run on another copy, tags nothing new.
        $this->artisan('loans:tag', ['--apply' => true])->expectsOutputToContain('Tagged 0 rows.')->assertSuccessful();
    }

    public function test_a_loan_from_before_the_records_starts_at_what_was_left(): void
    {
        for ($n = 6; $n <= 36; $n++) {
            $date = Carbon::parse('2016-12-06')->addMonthsNoOverflow($n - 6);
            $due = $date->copy()->addDays(42)->toDateString();
            $this->row($this->card, $date->toDateString(), 'charge', '6250', "INSTALMENT {$n} OF 36", $due);
            $this->row($this->card, $date->toDateString(), 'charge', '360', "INSTALMENT {$n} OF 36", $due);
        }

        $this->artisan('loans:tag', ['--apply' => true])->assertSuccessful();

        $first = Transaction::with('meta')->where('amount', '6250')->orderBy('date')->first();
        $this->assertSame('225000', $first->meta->meta['loan_borrowed']);
        $this->assertSame('31250', $first->meta->meta['loan_repaid_before']);
        $this->assertSame(31, Transaction::whereHas('meta', fn ($q) => $q->where('meta->loan', 'MASTER INSTALMENT LOAN'))->count());

        // 225,000 less instalments 1 to 5: owed from the first recorded charge, and that charge
        // not repaid until its statement falls due in January.
        $this->assertSame('193750.0000', (new NetWorth)->on('2016-12-31')['loans']);
        $this->assertSame('187500.0000', (new NetWorth)->on('2017-01-31')['loans']);

        // The last instalment, charged in June, is owed until its due date in July.
        $this->assertSame('6250.0000', (new NetWorth)->on('2019-06-30')['loans']);
        $this->assertSame('0.0000', (new NetWorth)->on('2019-07-31')['loans']);
    }

    public function test_a_loan_with_a_missing_instalment_is_left_untagged(): void
    {
        $this->taxLoan();
        Transaction::where('type', 'withdraw')->orderBy('date')->skip(10)->first()->delete();

        $this->artisan('loans:tag', ['--apply' => true])
            ->expectsOutputToContain('HSBC TAX LOAN: the repayments are not instalments 1 to 24')
            ->assertFailed();

        $this->assertSame(0, Transaction::whereHas('meta')->count());
    }

    public function test_an_edit_keeps_the_tags_and_untag_removes_them(): void
    {
        $this->taxLoan();
        $this->artisan('loans:tag', ['--apply' => true]);

        $row = Transaction::where('type', 'withdraw')->orderBy('date')->first();

        $this->put("/transactions/{$row->id}", [
            'account_id' => $this->saving->id,
            'date' => $row->date,
            'type' => 'withdraw',
            'description' => 'HSBC TAX LOAN',
            'amount' => '11574',
            'ccy' => 'HKD',
            'status' => 'posted',
            'meta_data' => ['loan' => 'FORGED', 'loan_principal' => '1'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['loan' => 'HSBC TAX LOAN', 'loan_principal' => '11250'], array_filter($row->fresh('meta')->meta->meta->getArrayCopy()));

        $this->artisan('loans:tag', ['--untag' => true, '--apply' => true])->assertSuccessful();
        $this->assertSame('0.0000', (new NetWorth)->on('2019-03-31')['loans']);
    }

    private function taxLoan(): void
    {
        $this->row($this->saving, '2019-01-29', 'deposit', '270000', 'HSBC TAX LOAN');

        for ($n = 0; $n < 24; $n++) {
            $date = Carbon::parse('2019-02-28')->addMonthsNoOverflow($n)->toDateString();
            $this->row($this->saving, $date, 'withdraw', '11574', 'HSBC TAX LOAN');
        }
    }

    private function row(Account $account, string $date, string $type, string $amount, string $description, ?string $due = null): void
    {
        $row = Transaction::create([
            'account_id' => $account->id, 'date' => $date, 'type' => $type,
            'description' => $description, 'amount' => $amount, 'ccy' => 'HKD', 'status' => 'posted',
        ]);

        if ($due !== null) {
            $row->meta()->create(['meta' => ['due_date' => $due]]);
        }
    }
}
