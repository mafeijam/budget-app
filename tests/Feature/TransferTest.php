<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\CashFlow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A transfer is two rows written as one: a withdrawal and a deposit naming each other, which
 * the cash flow reads as neither income nor spending. What is pinned here is that the pair
 * is only ever written, rewritten and deleted together.
 */
class TransferTest extends TestCase
{
    use RefreshDatabase;

    private Account $saving;

    private Account $current;

    private Account $yen;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-15 12:00:00');

        $this->saving = Account::create(['name' => 'Saving', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->current = Account::create(['name' => 'Current', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->yen = Account::create(['name' => 'Yen', 'status' => 'active', 'type' => 'cash', 'ccy' => 'JPY']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_transfer_writes_a_paired_withdrawal_and_deposit(): void
    {
        $this->post('/transfers', $this->body())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', 'Transfer of 500 HKD from [Saving] to [Current] recorded');

        [$out, $in] = $this->pair();

        $this->assertSame(['withdraw', 'Saving', '500.0000', 'TRANSFER TO CURRENT'], [$out->type, $out->account->name, $out->amount, $out->description]);
        $this->assertSame(['deposit', 'Current', '500.0000', 'TRANSFER FROM SAVING'], [$in->type, $in->account->name, $in->amount, $in->description]);
        $this->assertSame($in->id, $out->meta->meta['paired_transaction_id']);
        $this->assertSame($out->id, $in->meta->meta['paired_transaction_id']);
        $this->assertNull($out->category_id);

        // Moved, not earned or spent.
        $month = collect(collect(CashFlow::lastMonths(today()))->firstWhere('ccy', 'HKD')['months'] ?? [])->last();
        $this->assertTrue($month === null || ($month['income'] === '0.0000' && $month['spending'] === '0.0000'));
    }

    public function test_an_exchange_takes_the_amount_received_in_the_other_currency(): void
    {
        $this->post('/transfers', $this->body(['to_account_id' => $this->yen->id]))
            ->assertSessionHasErrors(['amount_in' => 'Enter how much arrived in JPY.']);

        $this->post('/transfers', $this->body(['to_account_id' => $this->yen->id, 'amount_in' => '9650']))
            ->assertSessionHasNoErrors();

        [$out, $in] = $this->pair();

        $this->assertSame(['500.0000', 'HKD'], [$out->amount, $out->ccy]);
        $this->assertSame(['9650.0000', 'JPY'], [$in->amount, $in->ccy]);
        $this->assertSame('EXCHANGE HKD TO JPY 9650', $out->description);
        $this->assertSame($out->description, $in->description);
    }

    public function test_it_refuses_what_is_not_a_transfer_between_two_cash_accounts(): void
    {
        $card = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);

        $this->post('/transfers', $this->body(['to_account_id' => $this->saving->id]))->assertSessionHasErrors('to_account_id');
        $this->post('/transfers', $this->body(['to_account_id' => $card->id]))->assertSessionHasErrors('to_account_id');
        $this->post('/transfers', $this->body(['amount_in' => '499']))->assertSessionHasErrors('amount_in');
        $this->post('/transfers', $this->body(['amount' => '0']))->assertSessionHasErrors('amount');

        $this->assertSame(0, Transaction::count());
    }

    public function test_either_half_edits_both_in_place(): void
    {
        $this->post('/transfers', $this->body())->assertSessionHasNoErrors();
        [$out, $in] = $this->pair();

        // Through the deposit: the withdrawal is still From.
        $this->put("/transfers/{$in->id}", $this->body([
            'from_account_id' => $this->current->id,
            'to_account_id' => $this->yen->id,
            'amount' => '200',
            'amount_in' => '3900',
            'date' => '2026-03-10',
        ]))->assertSessionHasNoErrors();

        [$newOut, $newIn] = $this->pair();

        $this->assertSame([$out->id, $in->id], [$newOut->id, $newIn->id]);
        $this->assertSame(['Current', '200.0000', '2026-03-10'], [$newOut->account->name, $newOut->amount, $newOut->date]);
        $this->assertSame(['Yen', '3900.0000', 'JPY'], [$newIn->account->name, $newIn->amount, $newIn->ccy]);
        $this->assertSame(2, Transaction::count());
    }

    public function test_a_half_is_not_edited_alone_and_is_deleted_with_the_other(): void
    {
        $this->post('/transfers', $this->body())->assertSessionHasNoErrors();
        [$out, $in] = $this->pair();

        $this->put("/transactions/{$out->id}", [
            'account_id' => $this->saving->id,
            'date' => $out->date,
            'type' => 'withdraw',
            'description' => $out->description,
            'amount' => '999',
            'ccy' => 'HKD',
            'status' => 'posted',
        ])->assertSessionHasErrors(['amount' => 'This withdraw is one half of a transfer, so its amount cannot be changed on its own. Edit it as a transfer.']);

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where("linked.{$out->id}.kind", 'transfer')
            ->where("linked.{$out->id}.account_id", $this->current->id)
        );

        $this->delete("/transactions/{$in->id}")->assertSessionHas('message', 'Transfer deleted in full: 2 transactions');

        $this->assertSame(0, Transaction::count());
    }

    public function test_only_a_transfers_half_opens_the_transfer_edit(): void
    {
        $lone = Transaction::create([
            'account_id' => $this->saving->id, 'date' => '2026-03-01', 'type' => 'withdraw',
            'description' => 'Cash', 'amount' => '10', 'ccy' => 'HKD', 'status' => 'posted',
        ]);

        $this->put("/transfers/{$lone->id}", $this->body())->assertSessionHasErrors('from_account_id');
    }

    /** @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return [
            'from_account_id' => $this->saving->id,
            'to_account_id' => $this->current->id,
            'date' => '2026-03-14',
            'amount' => '500',
            ...$overrides,
        ];
    }

    /** @return array{0: Transaction, 1: Transaction} out, in */
    private function pair(): array
    {
        $rows = Transaction::with(['meta', 'account'])->get();

        return [$rows->firstWhere('type', 'withdraw'), $rows->firstWhere('type', 'deposit')];
    }
}
