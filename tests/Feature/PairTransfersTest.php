<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PairTransfersTest extends TestCase
{
    use RefreshDatabase;

    private Account $saving;

    private Account $futu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saving = Account::create(['name' => 'SAVING', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->futu = Account::create(['name' => 'FUTU', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
    }

    public function test_it_pairs_an_exchange_into_a_new_currency_account_and_a_transfer_a_day_apart(): void
    {
        $exchange = $this->row($this->saving, 'withdraw', '2022-04-28', '9879.32', 'EXCHANGE HKD TO YEN 160,000');
        $out = $this->row($this->saving, 'withdraw', '2021-06-02', '10000', 'TRANSFER TO FUTU');
        $in = $this->row($this->futu, 'deposit', '2021-06-03', '10000', 'TRANSFER FROM SAVING');

        // Without --apply it reports and writes nothing.
        $this->artisan('transfers:pair')->expectsOutputToContain('1 exchange and 1 transfer to pair.')->assertSuccessful();
        $this->assertNull($exchange->fresh()->meta);
        $this->assertFalse(Account::where('ccy', 'JPY')->exists());

        $this->artisan('transfers:pair', ['--apply' => true])->assertSuccessful();

        // The yen arrived on an account made for it, named as the description names it.
        $yen = Account::where('ccy', 'JPY')->sole();
        $this->assertSame(['YEN', 'cash'], [$yen->name, $yen->type]);
        $deposit = Transaction::where('account_id', $yen->id)->sole();
        $this->assertSame(['2022-04-28', 'deposit', '160000.0000', 'JPY'], [$deposit->date, $deposit->type, $deposit->amount, $deposit->ccy]);
        $this->assertSame($deposit->id, $exchange->fresh()->meta->meta['paired_transaction_id']);
        $this->assertSame($exchange->id, $deposit->meta->meta['paired_transaction_id']);

        $this->assertSame($in->id, $out->fresh()->meta->meta['paired_transaction_id']);
        $this->assertSame($out->id, $in->fresh()->meta->meta['paired_transaction_id']);

        // A second run finds nothing: only unpaired rows are read, and the account is reused.
        $this->artisan('transfers:pair', ['--apply' => true])->expectsOutputToContain('0 exchanges and 0 transfers to pair.')->assertSuccessful();
        $this->assertSame(1, Account::where('ccy', 'JPY')->count());
    }

    public function test_two_same_day_transfers_a_day_apart_are_not_crossed(): void
    {
        // Each has its partner on its own day, which CashFlow already reads as a transfer.
        // Paired across the days, the 10th's withdrawal took the 11th's deposit.
        $this->row($this->saving, 'withdraw', '2021-06-10', '50000', 'TRANSFER TO FUTU');
        $this->row($this->futu, 'deposit', '2021-06-10', '50000', 'TRANSFER FROM SAVING');
        $this->row($this->saving, 'withdraw', '2021-06-11', '50000', 'TRANSFER TO FUTU');
        $this->row($this->futu, 'deposit', '2021-06-11', '50000', 'TRANSFER FROM SAVING');

        $this->artisan('transfers:pair', ['--apply' => true])->expectsOutputToContain('0 exchanges and 0 transfers to pair.')->assertSuccessful();
    }

    public function test_an_amount_a_day_apart_without_the_accounts_named_is_not_a_transfer(): void
    {
        // A transfer in and a card payment out the next day, the same amount: not one.
        $this->row($this->futu, 'deposit', '2017-07-19', '6610', 'TRANSFER FROM SAVING');
        $this->row($this->saving, 'withdraw', '2017-07-20', '6610', 'Card payment [MASTER]');
        // Named, but four days apart.
        $this->row($this->saving, 'withdraw', '2017-08-01', '700', 'TRANSFER TO FUTU');
        $this->row($this->futu, 'deposit', '2017-08-05', '700', 'TRANSFER FROM SAVING');

        $this->artisan('transfers:pair', ['--apply' => true])->expectsOutputToContain('0 exchanges and 0 transfers to pair.')->assertSuccessful();
    }

    private function row(Account $account, string $type, string $date, string $amount, string $description): Transaction
    {
        return Transaction::create([
            'account_id' => $account->id,
            'category_id' => null,
            'date' => $date,
            'type' => $type,
            'description' => $description,
            'amount' => $amount,
            'ccy' => $account->ccy,
            'status' => 'posted',
        ]);
    }
}
