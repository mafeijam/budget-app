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

        // A row an earlier run tagged with the principal alone loses that key.
        $old = Transaction::where('type', 'withdraw')->orderBy('date')->first();
        $old->meta()->create(['meta' => ['loan' => 'HSBC TAX LOAN', 'loan_principal' => '11250']]);

        $this->artisan('loans:tag', ['--apply' => true])->assertSuccessful();

        $this->assertSame(['loan' => 'HSBC TAX LOAN', 'loan_repaid' => '11574'], $old->fresh('meta')->meta->meta->getArrayCopy());

        // 270,000 borrowed with 7,776 of interest, all owed from the day it was, less one
        // whole 11,574 instalment.
        $february = (new NetWorth)->on('2019-02-28');
        $this->assertSame('266202.0000', $february['loans']);
        $this->assertSame('258426.0000', $february['cash']);
        $this->assertSame('-7776.0000', $february['net_worth']);
        $this->assertSame([['name' => 'HSBC TAX LOAN', 'ccy' => 'HKD', 'owed' => '266202.0000', 'base' => '266202.0000']], $february['loan_rows']);

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
        $this->assertSame('12960', $first->meta->meta['loan_interest']);
        $this->assertSame('33050', $first->meta->meta['loan_repaid_before']);
        $this->assertSame(62, Transaction::whereHas('meta', fn ($q) => $q->where('meta->loan', 'MASTER INSTALMENT LOAN'))->count());

        // 225,000 and 12,960 of interest, less instalments 1 to 5 at 6,610: owed from the first
        // recorded charge, and that charge not repaid until its statement falls due in January.
        $this->assertSame('204910.0000', (new NetWorth)->on('2016-12-31')['loans']);
        $this->assertSame('198300.0000', (new NetWorth)->on('2017-01-31')['loans']);

        // The last instalment, charged in June, is owed until its due date in July.
        $this->assertSame('6610.0000', (new NetWorth)->on('2019-06-30')['loans']);
        $this->assertSame('0.0000', (new NetWorth)->on('2019-07-31')['loans']);
    }

    public function test_a_loan_with_a_missing_instalment_is_left_untagged(): void
    {
        $this->taxLoan();
        Transaction::where('type', 'withdraw')->orderBy('date')->skip(10)->first()->delete();

        $this->artisan('loans:tag', ['--apply' => true])
            ->expectsOutputToContain('HSBC TAX LOAN: the repayments of 11574 are not instalments 1 to 24')
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
            'meta_data' => ['loan' => 'FORGED', 'loan_repaid' => '1'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['loan' => 'HSBC TAX LOAN', 'loan_repaid' => '11574'], array_filter($row->fresh('meta')->meta->meta->getArrayCopy()));

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
