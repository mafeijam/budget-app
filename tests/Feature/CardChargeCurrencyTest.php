<?php

namespace Tests\Feature;

use App\DTO\TransactionMetaData;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\CardStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A charge in one currency on a card denominated in another.
 *
 * A card's charges may be in whatever currency the merchant charged in, while the
 * statement is in the card's own. The figure a card owes is therefore not the sum of
 * the amounts on its charge rows -- it is the sum of what they came to *in the card's
 * currency*, which is this file's subject.
 *
 * There is no rate and no conversion. The user types the card-currency amount when
 * they record the charge, which is the one number in the pair that cannot be inferred
 * from the other, and the app has no rate source to infer it from. `card_amount` is
 * required exactly when the two currencies differ, rather than optional with a
 * fallback to `amount`: a forgotten figure would otherwise contribute the raw amount
 * in the wrong currency, and a statement that is quietly wrong is worse than one
 * that refuses to be recorded.
 *
 * The settlement side needs none of this. A payment is in the card's currency by
 * definition -- that is what a statement is denominated in -- and the bank it is paid
 * from must be in the same currency, so the paired transfer is already in the right
 * one. AccountData::guardSettlementAccount() keeps that rule, and this file pins the
 * half of the problem it cannot solve.
 */
class CardChargeCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Account $card;

    private Account $bank;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        // Terms and a same-currency bank, so the card is settleable and the settlement
        // tests below are about the currency rather than about a missing link.
        $this->card = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $this->card->meta()->create([
            'meta' => [
                'term_days' => 15,
                'statement_day' => 25,
                'settlement_account_id' => $this->bank->id,
            ],
        ]);

        $this->category = DB::table('categories')->insertGetId(['name' => 'FOOD']);
    }

    // ---------------------------------------------------------------------
    // The field itself
    // ---------------------------------------------------------------------

    public function test_the_field_is_named_card_amount_and_declared_in_the_meta_dto(): void
    {
        // Named in the assertion rather than left implicit, because the name is the
        // contract with the form: it is a key in the bag, and a client or a reader
        // who guesses wrong gets a charge that stores nothing.
        $this->assertTrue(
            property_exists(TransactionMetaData::class, 'card_amount'),
            'TransactionMetaData has no card_amount property.'
        );
    }

    // ---------------------------------------------------------------------
    // When it is required
    // ---------------------------------------------------------------------

    public function test_a_charge_in_the_cards_own_currency_needs_no_figure(): void
    {
        $this->post('/transactions', $this->charge(['amount' => '120.0000']))->assertSessionHasNoErrors();

        $this->assertSame('120.0000', Transaction::firstOrFail()->amount);
        // Absent rather than present-and-null, because the controller filters nulls out
        // of the bag on the way in -- which is also what makes the statement's fallback
        // to `amount` a same-currency amount rather than a raw foreign one.
        $this->assertNull(Transaction::firstOrFail()->meta_data['card_amount'] ?? null);
    }

    public function test_a_figure_on_a_charge_already_in_the_cards_currency_is_refused(): void
    {
        // The other direction, and the reason the field cannot simply be optional. A
        // figure left over from when the charge was entered in another currency would
        // be preferred over the amount by CardStatement and silently replace it in what
        // the card owes. So the meaningless case is refused rather than ignored -- a
        // stale value is exactly what a client sends when the currency is changed back.
        $this->post('/transactions', $this->charge([
            'ccy' => 'HKD',
            'meta_data' => ['merchant' => 'Cafe', 'card_amount' => '780.0000'],
        ]))->assertSessionHasErrors([
            'meta_data.card_amount' => 'This charge is already in the card\'s currency (HKD), so it needs no separate amount.',
        ]);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_charge_in_another_currency_without_a_figure_is_rejected(): void
    {
        // The trap this rule closes. A USD 100 charge on an HKD card, left unrecorded,
        // would contribute 100 to a statement denominated in HKD -- a figure that is
        // wrong by whatever the rate was and reports nothing.
        $this->post('/transactions', $this->charge(['ccy' => 'USD']))
            ->assertSessionHasErrors([
                'meta_data.card_amount' => 'This charge is in USD and the card is in HKD, so the amount in the card\'s currency is required.',
            ]);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_charge_in_another_currency_with_a_figure_is_accepted(): void
    {
        $this->post('/transactions', $this->charge([
            'ccy' => 'USD',
            'meta_data' => ['merchant' => 'Cafe', 'card_amount' => '780.0000'],
        ]))->assertSessionHasNoErrors();

        $transaction = Transaction::firstOrFail();

        $this->assertSame('USD', $transaction->ccy);
        $this->assertSame('100.0000', $transaction->amount);
        $this->assertSame('780.0000', $transaction->meta_data['card_amount']);
    }

    public function test_a_figure_must_be_positive(): void
    {
        // Zero is not a card-currency amount, it is a missing one wearing a value, and
        // it would contribute nothing to the statement while looking recorded.
        $this->post('/transactions', $this->charge([
            'ccy' => 'USD',
            'meta_data' => ['merchant' => 'Cafe', 'card_amount' => '0.0000'],
        ]))->assertSessionHasErrors('meta_data.card_amount');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_figure_must_be_a_decimal_to_four_places(): void
    {
        $this->post('/transactions', $this->charge([
            'ccy' => 'USD',
            'meta_data' => ['merchant' => 'Cafe', 'card_amount' => '780.00001'],
        ]))->assertSessionHasErrors('meta_data.card_amount');
    }

    public function test_a_figure_wider_than_the_amount_column_is_refused(): void
    {
        // The same ceiling the amount has, and for the same reason: this figure is
        // summed into the statement, so a value past the column's width would be
        // rounded by the database rather than refused.
        $this->post('/transactions', $this->charge([
            'ccy' => 'USD',
            'meta_data' => ['merchant' => 'Cafe', 'card_amount' => '999999999.9999'],
        ]))->assertSessionHasErrors('meta_data.card_amount');
    }

    public function test_a_payment_needs_no_figure_whatever_its_currency(): void
    {
        // A payment is in the card's currency by definition -- that is what a
        // statement is denominated in -- and AccountData::guardSettlementAccount()
        // keeps the bank in the same one, so there is nothing to convert on this side
        // either. A figure demanded here would be a field with no meaning.
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => null,
            'date' => '2026-02-01',
            'type' => 'payment',
            'description' => 'Payment',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => ['due_date' => '2026-02-09'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('120.0000', Transaction::firstOrFail()->amount);
    }

    public function test_a_cash_expense_needs_no_figure(): void
    {
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'date' => '2026-01-10',
            'type' => 'expense',
            'description' => 'Lunch',
            'amount' => '100.0000',
            'ccy' => 'USD',
        ])->assertSessionHasNoErrors();

        $this->assertSame('100.0000', Transaction::firstOrFail()->amount);
    }

    // ---------------------------------------------------------------------
    // What the statement does with it
    // ---------------------------------------------------------------------

    public function test_the_statement_sums_the_figure_rather_than_the_amount(): void
    {
        $this->post('/transactions', $this->charge([
            'ccy' => 'USD',
            'meta_data' => ['merchant' => 'Cafe', 'card_amount' => '780.0000'],
        ]))->assertSessionHasNoErrors();

        $statement = CardStatement::forAccount($this->card)->sole();

        // 780 HKD, not the 100 USD on the row. The card owes its own currency.
        $this->assertSame('780.0000', $statement->charged);
        $this->assertSame('780.0000', $statement->owed());
    }

    public function test_a_period_mixing_currencies_sums_each_the_right_way(): void
    {
        $this->post('/transactions', $this->charge([
            'date' => '2026-01-01',
            'ccy' => 'HKD',
            'amount' => '120.0000',
        ]))->assertSessionHasNoErrors();

        $this->post('/transactions', $this->charge([
            'date' => '2026-01-05',
            'ccy' => 'USD',
            'meta_data' => ['merchant' => 'Books', 'card_amount' => '780.0000'],
        ]))->assertSessionHasNoErrors();

        // 120 HKD + 780 HKD. Reading the raw amounts would give 220, which is 120 HKD
        // plus 100 USD added together as though they were the same thing.
        $this->assertSame('900.0000', CardStatement::forAccount($this->card)->sole()->charged);
    }

    public function test_a_charge_in_the_cards_own_currency_still_sums_its_amount(): void
    {
        $this->post('/transactions', $this->charge(['ccy' => 'HKD', 'amount' => '120.0000']))
            ->assertSessionHasNoErrors();

        $this->assertSame('120.0000', CardStatement::forAccount($this->card)->sole()->charged);
    }

    public function test_settling_pays_the_card_currency_figure(): void
    {
        $this->post('/transactions', $this->charge([
            'ccy' => 'USD',
            'meta_data' => ['merchant' => 'Cafe', 'card_amount' => '780.0000'],
        ]))->assertSessionHasNoErrors();

        $this->post("/accounts/{$this->card->id}/settle", [
            'due_date' => '2026-02-09',
            'owed' => '780.0000',
        ])->assertSessionHasNoErrors();

        $payment = Transaction::where('type', 'payment')->firstOrFail();
        $transfer = Transaction::where('type', 'transfer')->firstOrFail();

        // Both in HKD and both the card-currency figure. The charge's own USD amount
        // is not the number that moves, and nothing about settling converts it.
        $this->assertSame('780.0000', $payment->amount);
        $this->assertSame('HKD', $payment->ccy);
        $this->assertSame('780.0000', $transfer->amount);
        $this->assertSame('HKD', $transfer->ccy);
    }

    public function test_settling_a_cross_currency_card_still_needs_a_same_currency_bank(): void
    {
        // The half of the problem this file does not solve. A charge may be in another
        // currency because the merchant charged in one; a card's *bank* may not be,
        // because the transfer that leaves it has no figure to convert with and no
        // rate to convert by. The refusal stands, deliberately.
        $usdBank = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);

        $this->post('/accounts', [
            'name' => 'Card USD Bank',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
            'meta_data' => [
                'term_days' => 15,
                'statement_day' => 25,
                'settlement_account_id' => $usdBank->id,
            ],
        ])->assertSessionHasErrors('meta_data.settlement_account_id');
    }

    public function test_the_figure_reaches_the_bag_and_the_form_reads_it_back(): void
    {
        $this->post('/transactions', $this->charge([
            'ccy' => 'USD',
            'meta_data' => ['merchant' => 'Cafe', 'card_amount' => '780.0000'],
        ]))->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn ($page) => $page
            ->where('data.data.0.amount', '100.0000')
            ->where('data.data.0.ccy', 'USD')
            ->where('data.data.0.meta_data.card_amount', '780.0000')
            // And the statement panel, which is the figure the user will settle.
            ->where('statements.0.periods.0.owed', '780.0000')
        );
    }

    private function charge(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '100.0000',
            'ccy' => 'HKD',
            'meta_data' => ['merchant' => 'Cafe'],
        ], $overrides);
    }
}
