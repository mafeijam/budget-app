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
 * Withdraw: money leaving a cash account.
 *
 * One of two cash types, and the reason a bank has two rather than four: expense
 * and transfer were both money out, income and deposit both money in, and nothing
 * downstream could tell either pair apart. AccountBalance's CASE reads the type for
 * a sign and the amount for a figure, so income and deposit were one row with two
 * names and expense and transfer were another.
 *
 * What the collapse deliberately gives up is the reason transfer was its own type.
 * It existed so that paying a credit card would not be counted as spending -- an
 * expense requires a category and lands in every spending total, so card repayments
 * would have inflated the figure the app exists to produce. A withdrawal is
 * categorised spending instead, and paying a card is recorded as one. That is right
 * for the categories page and wrong for a spending report, which this app does not
 * have: nothing reads a type to decide what was spent, so the distinction had no
 * reader to inform.
 *
 * Cash only. A payment is the card-side of a settlement and already has its own
 * type, so a withdrawal on a card would be a second name for it.
 */
class WithdrawTest extends TestCase
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

    public function test_a_withdrawal_is_legal_only_on_a_cash_account(): void
    {
        $this->assertSame([AccountType::Cash], TransactionType::Withdraw->accountTypes());
        $this->assertTrue(TransactionType::Withdraw->isAllowedFor(AccountType::Cash));
        $this->assertFalse(TransactionType::Withdraw->isAllowedFor(AccountType::Card));
        $this->assertFalse(TransactionType::Withdraw->isAllowedFor(AccountType::Security));
    }

    public function test_a_withdrawal_needs_no_category_but_may_carry_one(): void
    {
        // Not required, and not because it is not spending. TradeCash writes a withdrawal
        // beside every buy and settle() writes one beside every card payment; requiring a
        // category would make the app's own rows unsaveable in the form, refused over a
        // field the user cannot supply. Permitted rather than prohibited, so a withdrawal
        // that really was spending can be labelled.
        $this->assertFalse(TransactionType::Withdraw->requiresCategory());

        $this->post('/transactions', $this->withdrawPayload(['category_id' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Transaction::firstOrFail()->category_id);

        $this->post('/transactions', $this->withdrawPayload(['date' => '2026-02-02']))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->category, Transaction::latest('id')->firstOrFail()->category_id);
    }

    public function test_a_withdrawal_keeps_the_amount_it_was_given(): void
    {
        $this->assertFalse(TransactionType::Withdraw->derivesAmount());
    }

    public function test_the_cash_account_offers_a_withdrawal_and_a_deposit_and_the_others_do_not(): void
    {
        // Derived from the enum, so the reduction from four cash types to two needed no
        // template and no list. Asserted through the endpoint the form reads.
        $this->get('/transactions')->assertInertia(fn ($page) => $page
            ->where('typeOptions.cash', ['withdraw', 'deposit'])
            ->where('typeOptions.card', ['charge', 'payment'])
            ->where('typeOptions.security', ['buy', 'sell', 'deposit'])
        );
    }

    // ---------------------------------------------------------------------
    // Recording one
    // ---------------------------------------------------------------------

    public function test_a_withdrawal_is_recorded(): void
    {
        $response = $this->post('/transactions', $this->withdrawPayload());

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', 'Transaction [withdraw] recorded');

        $transaction = Transaction::firstOrFail();

        $this->assertSame('withdraw', $transaction->type);
        $this->assertSame($this->bank->id, $transaction->account_id);
        $this->assertSame('1200.0000', $transaction->amount);
        $this->assertSame($this->category, $transaction->category_id);
        $this->assertSame('posted', $transaction->status);
    }

    public function test_a_withdrawal_may_carry_a_second_label_as_payment_may(): void
    {
        // A charge is the one type that requires one; a payment and a deposit are both
        // optional, and neither is prohibited from carrying an inert label.
        $this->assertTrue(TransactionType::Charge->requiresCategory());
        $this->assertFalse(TransactionType::Payment->requiresCategory());
        $this->assertFalse(TransactionType::Deposit->requiresCategory());
    }

    public function test_a_withdrawal_requires_an_amount(): void
    {
        // It is not a trade, so nothing derives it and the client has to supply it.
        $this->post('/transactions', $this->withdrawPayload(['amount' => null]))
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_withdrawal_records_no_bag(): void
    {
        // No statement period, no card-currency figure, no trade fields. A bag here
        // would be a row reading "{}" for no information.
        $this->post('/transactions', $this->withdrawPayload())->assertSessionHasNoErrors();

        $this->assertSame(0, Meta::where('model_type', Transaction::class)->count());
    }

    public function test_a_withdrawal_cannot_be_recorded_on_a_card(): void
    {
        // Not merely unoffered by the picker -- refused, because the form is not the
        // only way a payload arrives.
        $this->post('/transactions', $this->withdrawPayload(['account_id' => $this->card->id]))
            ->assertSessionHasErrors('type');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_withdrawal_does_not_reduce_what_the_card_owes(): void
    {
        // A settlement writes a payment on the card and a withdrawal on the bank, and
        // only the first may reach a statement. A payment is grouped by due_date and nets
        // against charges; a withdrawal is on another account entirely and is grouped by
        // nothing. If one leaked into a statement figure, settling a card would appear to
        // work by accident.
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

        $this->post('/transactions', $this->withdrawPayload(['amount' => '120.0000']))
            ->assertSessionHasNoErrors();

        $this->assertSame('120.0000', CardStatement::forAccount($this->card)->sole()->owed());
    }

    public function test_a_withdrawal_is_edited_and_deleted_like_any_other(): void
    {
        $this->post('/transactions', $this->withdrawPayload())->assertSessionHasNoErrors();

        $id = Transaction::firstOrFail()->id;

        $this->put("/transactions/{$id}", $this->withdrawPayload(['amount' => '1500.0000']))
            ->assertSessionHas('message', 'Transaction [withdraw] updated');

        $this->assertSame('1500.0000', Transaction::firstOrFail()->amount);

        $this->delete("/transactions/{$id}")
            ->assertSessionHas('message', 'Transaction [withdraw] deleted');

        $this->assertDatabaseCount('transactions', 0);
    }

    private function withdrawPayload(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'date' => '2026-02-01',
            'type' => 'withdraw',
            'description' => 'Card payment',
            'amount' => '1200.0000',
            'ccy' => 'HKD',
        ], $overrides);
    }
}
