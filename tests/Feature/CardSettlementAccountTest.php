<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A credit card naming the bank it is paid from.
 *
 * settlement_account_id was built for a brokerage settling trades, and
 * AccountMetaData prohibited it on every type but securities -- so a card had no way
 * to say which account a payment leaves, and settling one would have to ask on every
 * occasion. The prohibition is relaxed here to cover cards, which is one word in one
 * rule; the guard in AccountData was already type-agnostic and is only its wording
 * that had to change.
 *
 * What does not change is the ccy requirement, and that is the load-bearing part. A
 * card may only be paid from a bank in its own currency, so a settlement writes two
 * rows of the same amount with no conversion between them -- which is what lets the
 * paired transfer skip fx_rate entirely.
 */
class CardSettlementAccountTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $usdBank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->usdBank = Account::create(['name' => 'Bank USD', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
    }

    public function test_a_card_may_name_the_bank_it_is_paid_from(): void
    {
        $card = $this->card();

        $this->post('/accounts', $this->cardPayload([
            'name' => 'Card A',
            'meta_data' => ['term_days' => 15, 'statement_day' => 25, 'settlement_account_id' => $this->bank->id],
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounts', ['id' => $card->id]);
        $this->assertSame(
            $this->bank->id,
            (int) Account::find($card->id + 1)->settlementAccount()?->id,
            'The card did not record the bank it is paid from.'
        );
    }

    public function test_a_card_without_one_is_still_valid(): void
    {
        // Optional, unlike a brokerage. A card can exist with no bank named and no
        // way to be settled from until one is; refusing it would mean a user could
        // not add a card before deciding where they pay it from.
        $this->post('/accounts', $this->cardPayload([
            'name' => 'Card B',
            'meta_data' => ['term_days' => 15, 'statement_day' => 25],
        ]))->assertSessionHasNoErrors();

        $card = Account::where('name', 'Card B')->firstOrFail();

        $this->assertNull($card->settlementAccount());
    }

    public function test_a_cash_account_still_may_not_name_one(): void
    {
        // The prohibition is narrowed to cash, not lifted. A cash account has nothing
        // to settle into: it is the thing settled into.
        $this->post('/accounts', [
            'name' => 'Plain',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'HKD',
            'meta_data' => ['settlement_account_id' => $this->bank->id],
        ])->assertSessionHasErrors('meta_data.settlement_account_id');

        $this->assertSame(2, Account::count()); // only the two setUp banks, so nothing was created
    }

    public function test_a_card_may_only_be_paid_from_a_cash_account(): void
    {
        $this->post('/accounts', $this->cardPayload([
            'name' => 'Card C',
            'meta_data' => [
                'term_days' => 15,
                'statement_day' => 25,
                'settlement_account_id' => $this->card()->id,
            ],
        ]))->assertSessionHasErrors('meta_data.settlement_account_id');
    }

    public function test_a_card_may_only_be_paid_from_a_bank_in_its_own_currency(): void
    {
        // The rule the paired transfer depends on. A USD card paid from an HKD bank
        // means the two rows of a settlement would be different amounts, and there is
        // no rate to convert with: fx_rate is declared and wired to nothing.
        $this->post('/accounts', $this->cardPayload([
            'name' => 'Card D',
            'meta_data' => [
                'term_days' => 15,
                'statement_day' => 25,
                'settlement_account_id' => $this->usdBank->id,
            ],
        ]))->assertSessionHasErrors('meta_data.settlement_account_id');
    }

    public function test_the_refusal_names_the_account_type_rather_than_saying_brokerage(): void
    {
        // The guard was written when only a securities account could hold the field,
        // and its messages say so. A user who names the wrong kind of target for a
        // card would be told "a securities account settles into a cash account" and
        // left to work out that the word did not apply to them.
        $response = $this->post('/accounts', $this->cardPayload([
            'name' => 'Card E',
            'meta_data' => [
                'term_days' => 15,
                'statement_day' => 25,
                'settlement_account_id' => $this->usdBank->id,
            ],
        ]));

        $response->assertSessionHasErrors([
            'meta_data.settlement_account_id' => 'A HKD card cannot be paid from a USD account.',
        ]);
    }

    public function test_changing_a_card_away_from_its_bank_drops_the_link(): void
    {
        $this->post('/accounts', $this->cardPayload([
            'name' => 'Card F',
            'meta_data' => ['term_days' => 15, 'statement_day' => 25, 'settlement_account_id' => $this->bank->id],
        ]))->assertSessionHasNoErrors();

        $card = Account::where('name', 'Card F')->firstOrFail();
        $this->assertNotNull($card->settlementAccount());

        // Now a plain cash account, which is what the same payload would produce
        // without the link. The field is prohibited there, so a stale one would fail
        // the save over a field the form no longer shows.
        $this->put("/accounts/{$card->id}", [
            'id' => $card->id,
            'name' => 'Card F',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'HKD',
            'meta_data' => ['settlement_account_id' => null],
        ])->assertSessionHasNoErrors();

        $this->assertNull($card->fresh()->settlementAccount());
    }

    public function test_changing_the_bank_keeps_the_card_terms(): void
    {
        $this->post('/accounts', $this->cardPayload([
            'name' => 'Card G',
            'meta_data' => ['term_days' => 15, 'statement_day' => 25, 'settlement_account_id' => $this->bank->id],
        ]))->assertSessionHasNoErrors();

        $card = Account::where('name', 'Card G')->firstOrFail();

        $this->put("/accounts/{$card->id}", [
            'id' => $card->id,
            'name' => 'Card G',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
            'meta_data' => [
                'term_days' => 20,
                'statement_day' => 8,
                'settlement_account_id' => $this->bank->id,
            ],
        ])->assertSessionHasNoErrors();

        $fresh = $card->fresh();

        $this->assertSame(20, $fresh->meta->meta['term_days']);
        $this->assertSame(8, $fresh->meta->meta['statement_day']);
        $this->assertSame($this->bank->id, (int) $fresh->settlementAccount()->id);
    }

    public function test_a_bank_named_by_a_card_cannot_be_deleted(): void
    {
        // The referential guard AccountController::destroy() already had for a
        // brokerage's settlement account, now covering cards. A card with no bank to
        // be paid from cannot be settled, and deleting the bank would make that
        // permanent with no record of why.
        $this->post('/accounts', $this->cardPayload([
            'name' => 'Card H',
            'meta_data' => ['term_days' => 15, 'statement_day' => 25, 'settlement_account_id' => $this->bank->id],
        ]))->assertSessionHasNoErrors();

        $this->delete("/accounts/{$this->bank->id}")
            ->assertSessionHas('message', 'Account [Bank] is the settlement account for [Card H] and cannot be deleted');

        $this->assertDatabaseHas('accounts', ['id' => $this->bank->id]);
    }

    private function card(): Account
    {
        return Account::create(['name' => 'Target', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
    }

    private function cardPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Card',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
        ], $overrides);
    }
}
