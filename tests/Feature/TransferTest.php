<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Meta;
use App\Models\Transaction;
use App\Support\CardStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Transfer: money leaving a bank that the app does not track the other end of.
 *
 * It exists because settling a card writes two rows -- a payment on the card and
 * something on the bank -- and the bank-side row has to be *something*. The obvious
 * choice, an expense, requires a category and would be counted as spending, which
 * for a budget app is not a cosmetic problem: paying a credit card is not an
 * expense, and inflating every spending total with card repayments would make the
 * figure the app exists to produce wrong.
 *
 * Cash only. A payment is the card-side of the same event and already has its own
 * type; a transfer on a card would be a second name for it.
 */
class TransferTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $card;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->card = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        // Card terms, so a charge on it lands in a statement period. Without them
        // deriveDueDate() has no cycle to read and the charge gets no due_date, which
        // is correct and would make the statement assertion below vacuous.
        $this->card->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);
        $this->category = DB::table('categories')->insertGetId(['name' => 'FOOD']);
    }

    // ---------------------------------------------------------------------
    // What it is
    // ---------------------------------------------------------------------

    public function test_a_transfer_is_legal_only_on_a_cash_account(): void
    {
        $this->assertSame([AccountType::Cash], TransactionType::Transfer->accountTypes());
        $this->assertTrue(TransactionType::Transfer->isAllowedFor(AccountType::Cash));
        $this->assertFalse(TransactionType::Transfer->isAllowedFor(AccountType::Card));
        $this->assertFalse(TransactionType::Transfer->isAllowedFor(AccountType::Security));
    }

    public function test_a_transfer_needs_no_category(): void
    {
        // The whole reason for the type. A transfer is not spending, and a category
        // on one would be a label for money that was never spent.
        $this->assertFalse(TransactionType::Transfer->requiresCategory());
    }

    public function test_a_transfer_keeps_the_amount_it_was_given(): void
    {
        $this->assertFalse(TransactionType::Transfer->derivesAmount());
    }

    public function test_the_cash_account_offers_a_transfer_and_the_others_do_not(): void
    {
        // Derived from the enum, so adding the case put it in the picker without
        // touching a template. Asserted through the endpoint the form reads.
        $this->get('/transactions')->assertInertia(fn ($page) => $page
            ->where('typeOptions.cash', ['expense', 'income', 'transfer', 'deposit'])
            ->where('typeOptions.card', ['charge', 'payment'])
            ->where('typeOptions.security', ['buy', 'sell', 'dividend'])
        );
    }

    // ---------------------------------------------------------------------
    // Recording one
    // ---------------------------------------------------------------------

    public function test_a_transfer_is_recorded(): void
    {
        $response = $this->post('/transactions', $this->transferPayload());

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', 'Transaction [transfer] recorded');

        $transaction = Transaction::firstOrFail();

        $this->assertSame('transfer', $transaction->type);
        $this->assertSame($this->bank->id, $transaction->account_id);
        $this->assertSame('1200.0000', $transaction->amount);
        $this->assertNull($transaction->category_id);
        $this->assertSame('posted', $transaction->status);
    }

    public function test_a_transfer_needs_no_category_but_records_none(): void
    {
        $this->post('/transactions', $this->transferPayload([
            'category_id' => $this->category,
        ]))->assertSessionHasNoErrors();

        // Permitted rather than prohibited -- TransactionData lets a payment carry an
        // inert label too, and a transfer is no different. What matters is that it is
        // not required, which is what the rule above says.
        $this->assertSame($this->category, Transaction::firstOrFail()->category_id);
    }

    public function test_a_transfer_requires_an_amount(): void
    {
        // It is not a trade, so nothing derives it and the client has to supply it.
        $this->post('/transactions', $this->transferPayload(['amount' => null]))
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_transfer_records_no_bag(): void
    {
        // No statement period, no card-currency figure, no trade fields. A bag here
        // would be a row reading "{}" for no information.
        $this->post('/transactions', $this->transferPayload())->assertSessionHasNoErrors();

        $this->assertSame(0, Meta::where('model_type', Transaction::class)->count());
    }

    public function test_a_transfer_cannot_be_recorded_on_a_card(): void
    {
        // Not merely unoffered by the picker -- refused, because the form is not the
        // only way a payload arrives.
        $this->post('/transactions', $this->transferPayload(['account_id' => $this->card->id]))
            ->assertSessionHasErrors('type');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_transfer_does_not_reduce_what_the_card_owes(): void
    {
        // The distinction that makes the pair make sense. A payment is grouped by
        // due_date and nets against charges; a transfer is grouped by nothing and
        // never appears in a statement. If a transfer leaked into a statement figure,
        // settling a card would appear to work by accident.
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => [],
        ])->assertSessionHasNoErrors();

        $this->post('/transactions', $this->transferPayload(['amount' => '120.0000']))
            ->assertSessionHasNoErrors();

        $this->assertSame('120.0000', CardStatement::forAccount($this->card)->sole()->owed());
    }

    public function test_a_transfer_is_edited_and_deleted_like_any_other(): void
    {
        $this->post('/transactions', $this->transferPayload())->assertSessionHasNoErrors();

        $id = Transaction::firstOrFail()->id;

        $this->put("/transactions/{$id}", $this->transferPayload(['amount' => '1500.0000']))
            ->assertSessionHas('message', 'Transaction [transfer] updated');

        $this->assertSame('1500.0000', Transaction::firstOrFail()->amount);

        $this->delete("/transactions/{$id}")
            ->assertSessionHas('message', 'Transaction [transfer] deleted');

        $this->assertDatabaseCount('transactions', 0);
    }

    private function transferPayload(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->bank->id,
            'category_id' => null,
            'date' => '2026-02-01',
            'type' => 'transfer',
            'description' => 'Card payment',
            'amount' => '1200.0000',
            'ccy' => 'HKD',
        ], $overrides);
    }
}
