<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Meta;
use App\Support\CardStatementCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Account',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'USD',
            'meta_data' => ['due' => 15, 'statement_day' => 25],
        ], $overrides);
    }

    public function test_index_renders_the_account_inertia_page(): void
    {
        Account::create(['name' => 'Alpha', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->get('/accounts');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('account')
            ->has('formEmpty')
            ->has('data')
            ->has('params')
            ->has('meta')
            ->where('meta.form', 'account-form')
            ->where('meta.path', '/accounts')
        );
    }

    public function test_index_exposes_pagination_and_sort_params(): void
    {
        $response = $this->get('/accounts');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('params.sort', 'created_at')
            ->where('params.dir', 'desc')
        );
    }

    public function test_index_offers_the_cash_accounts_a_brokerage_can_settle_into(): void
    {
        // The settlement picker's whole option list. Cash accounts only, because
        // every other type is refused by AccountData, so offering them would be
        // offering a choice that cannot be submitted.
        //
        // Ids are compared against the created models rather than hardcoded:
        // RefreshDatabase rolls back rows but not AUTO_INCREMENT, so the values
        // depend on how many accounts earlier tests in the process created.
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $bankTwo = Account::create(['name' => 'Bank Two', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);

        $response = $this->get('/accounts');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('account')
            ->has('settlementOptions', 2)
            ->where('settlementOptions.0.label', 'Bank (HKD)')
            ->where('settlementOptions.0.value', $bank->id)
            ->where('settlementOptions.1.label', 'Bank Two (USD)')
            ->where('settlementOptions.1.value', $bankTwo->id)
        );
    }

    public function test_index_offers_a_cash_account_the_user_has_closed(): void
    {
        // Status is orthogonal to settlement, and AccountData allows an inactive
        // target. Excluding it from the picker would make an account that saves
        // cleanly impossible to re-point at, so the list is filtered by type only.
        Account::create(['name' => 'Closed Bank', 'status' => 'inactive', 'type' => 'cash', 'ccy' => 'HKD']);

        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->has('settlementOptions', 1)
            ->where('settlementOptions.0.label', 'Closed Bank (HKD)')
        );
    }

    public function test_index_orders_the_settlement_picker_by_name(): void
    {
        // A picker ordered by id is a picker whose contents move around as
        // accounts are created, which reads as the list reordering itself.
        foreach (['Zebra', 'Alpha', 'Middle'] as $name) {
            Account::create(['name' => $name, 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        }

        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->where('settlementOptions.0.label', 'Alpha (HKD)')
            ->where('settlementOptions.1.label', 'Middle (HKD)')
            ->where('settlementOptions.2.label', 'Zebra (HKD)')
        );
    }

    public function test_the_option_list_is_not_the_paginated_page_of_accounts(): void
    {
        // The picker must reach every cash account, not the handful on the
        // current page. The account table paginates at 5 by default, so reusing
        // that result set would quietly make most banks unselectable.
        foreach (range(1, 8) as $n) {
            Account::create(['name' => "Bank $n", 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        }

        $response = $this->get('/accounts');

        $response->assertInertia(fn (Assert $page) => $page->has('settlementOptions', 8));
        // ...while the table itself stays paginated.
        $response->assertInertia(fn (Assert $page) => $page->has('data.data', 5));
    }

    public function test_index_exposes_the_picker_even_with_no_accounts(): void
    {
        // An empty list, not a missing key. A q-select bound to undefined
        // options renders as a broken control rather than an empty one.
        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->component('account')
            ->has('settlementOptions', 0)
        );
    }

    public function test_the_payload_the_security_form_now_sends_is_accepted(): void
    {
        // The form's exact card-and-link payload. Asserted end to end because the
        // fields it sends are hand-maintained on both sides: when settlement
        // became required for a brokerage, the form had no control for it and
        // every securities account stopped being creatable through the browser
        // while the API tests stayed green.
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $response = $this->post('/accounts', [
            'name' => 'Broker',
            'status' => 'active',
            'type' => 'security',
            'ccy' => 'HKD',
            'settlement_account_id' => $bank->id,
            'meta_data' => ['due' => null, 'statement_day' => null],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', 'Account [Broker] created');
        $this->assertSame($bank->id, Account::firstWhere('name', 'Broker')->settlement_account_id);
    }

    public function test_the_payload_the_card_form_now_sends_is_accepted(): void
    {
        // Same reason, and pre-existing rather than introduced here: the form's
        // card branch sent `due` but never `statement_day`, which is
        // required_if:type,card, so creating a card account through the browser
        // has been rejected since that rule landed. Quasar's number input emits
        // null for a blank field, which is what a cleared box sends.
        $this->post('/accounts', [
            'name' => 'Card',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
            'settlement_account_id' => null,
            'meta_data' => ['due' => 15, 'statement_day' => 25],
        ])->assertSessionHasNoErrors();

        $this->assertSame(25, Meta::firstWhere('model_id', Account::firstWhere('name', 'Card')->id)->meta['statement_day']);
    }

    public function test_a_card_form_submitted_with_the_statement_day_cleared_is_rejected(): void
    {
        // The failure the previous test's fix prevents, pinned so the form
        // cannot regress into sending a bare `due` again.
        $response = $this->post('/accounts', [
            'name' => 'Card',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
            'settlement_account_id' => null,
            'meta_data' => ['due' => 15, 'statement_day' => null],
        ]);

        $response->assertSessionHasErrors('meta_data.statement_day');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_a_date_shaped_due_day_is_rejected(): void
    {
        // The whole point of retyping `due`. This exact value used to validate
        // and store: the rule was `max:28`, which measured the length of a
        // string, so '2026-10-01' sailed through. CardStatementCycle then
        // refused to build a cycle for it (it requires both card terms to be
        // numeric), so the account saved cleanly and every charge placed on it
        // derived a null due_date. Silent incompleteness, no error anywhere.
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['due' => '2026-10-01', 'statement_day' => 25],
        ]));

        $response->assertSessionHasErrors('meta_data.due');
        $this->assertDatabaseCount('accounts', 0);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidDueDayProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'past the end of the month' => [32],
            'not a number at all' => ['soon'],
            'a float' => [15.5],
        ];
    }

    #[DataProvider('invalidDueDayProvider')]
    public function test_a_due_day_outside_the_calendar_is_rejected(mixed $due): void
    {
        // The old `max:28` accepted all of these. A day of 32 is a data entry
        // error rather than a short month, which is why CardStatementCycle
        // throws on it rather than clamping -- so validation has to agree with
        // the guard instead of quietly accepting what the guard will refuse.
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['due' => $due, 'statement_day' => 25],
        ]));

        $response->assertSessionHasErrors('meta_data.due');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_the_due_day_is_stored_as_a_number(): void
    {
        // Meta is JSON, so '15' and 15 are different values and the distinction
        // survives the round trip. CardStatementCycle casts with is_numeric so
        // it tolerates either, but storing the string form is how the field
        // became ambiguous in the first place.
        $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['due' => 15, 'statement_day' => 25],
        ]))->assertSessionHasNoErrors();

        $meta = Meta::firstWhere('model_id', Account::firstWhere('name', 'Test Account')->id);

        $this->assertSame(15, $meta->meta['due']);
        $this->assertIsInt($meta->meta['due']);
    }

    public function test_a_card_built_from_a_stored_due_day_derives_a_due_date(): void
    {
        // The end of the ambiguity: a card that saves a due day is now
        // guaranteed to place its charges in a statement period. Before the
        // retype, a date-shaped value produced an account that looked complete
        // and derived nothing.
        Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $card = Account::firstWhere('name', 'Card');
        $card->meta()->create(['meta' => ['due' => 15, 'statement_day' => 25]]);

        $cycle = CardStatementCycle::fromMeta($card->fresh()->meta->meta);

        $this->assertNotNull($cycle);
        $this->assertSame(15, $cycle->dueDay());
        $this->assertSame(25, $cycle->statementDay());
    }

    public function test_store_creates_the_account(): void
    {
        $response = $this->post('/accounts', $this->payload());

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Account [Test Account] created');

        $this->assertDatabaseHas('accounts', [
            'name' => 'Test Account',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'USD',
        ]);
    }

    public function test_store_creates_a_meta_row_when_meta_data_is_supplied(): void
    {
        $this->post('/accounts', $this->payload());

        $account = Account::firstWhere('name', 'Test Account');

        $this->assertNotNull($account);
        $this->assertDatabaseHas('meta', [
            'model_id' => $account->id,
            'model_type' => Account::class,
        ]);

        $this->assertSame(15, $account->meta->meta['due']);
    }

    public function test_store_omits_the_meta_row_when_meta_data_is_blank(): void
    {
        // type=cash so nothing in meta is required, which is what makes this
        // test meaningful: the account is actually created, and the meta row is
        // absent because the payload was blank rather than because validation
        // rejected the whole request.
        $this->post('/accounts', $this->payload([
            'type' => 'cash',
            'meta_data' => ['due' => null, 'statement_day' => null],
        ]));

        $this->assertDatabaseCount('accounts', 1);
        $this->assertDatabaseCount('meta', 0);
    }

    public function test_store_rejects_a_duplicate_name(): void
    {
        Account::create(['name' => 'Taken', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->post('/accounts', $this->payload(['name' => 'Taken']));

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_store_rejects_an_unknown_account_type(): void
    {
        // Previously "banana" was accepted and written straight to the column,
        // because only `name` had validation rules.
        $response = $this->post('/accounts', $this->payload(['type' => 'banana']));

        $response->assertSessionHasErrors('type');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_store_rejects_a_currency_longer_than_three_characters(): void
    {
        $response = $this->post('/accounts', $this->payload(['ccy' => 'HK Dollar']));

        $response->assertSessionHasErrors('ccy');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_update_rejects_an_unknown_account_status(): void
    {
        $account = Account::create(['name' => 'Old', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'status' => 'purple',
        ]));

        $response->assertSessionHasErrors('status');
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_store_requires_due_date_when_type_is_card(): void
    {
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['due' => null, 'statement_day' => 25],
        ]));

        $response->assertSessionHasErrors('meta_data.due');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_store_requires_a_statement_day_when_type_is_card(): void
    {
        // A card with a due day but no statement day cannot be settled against:
        // nothing says which statement the charge belongs to. Rejecting it at
        // the door is the point -- the alternative is an account that saves
        // cleanly and then silently derives no due date for any charge on it.
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['due' => 15, 'statement_day' => null],
        ]));

        $response->assertSessionHasErrors('meta_data.statement_day');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_store_rejects_a_statement_day_outside_the_calendar(): void
    {
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['due' => 15, 'statement_day' => 45],
        ]));

        $response->assertSessionHasErrors('meta_data.statement_day');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_store_creates_a_securities_account_that_settles_into_cash(): void
    {
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $this->post('/accounts', $this->payload([
            'name' => 'Broker',
            'type' => 'security',
            'meta_data' => [],
            'settlement_account_id' => $bank->id,
        ]));

        $broker = Account::firstWhere('name', 'Broker');

        $this->assertNotNull($broker);
        $this->assertSame($bank->id, $broker->settlement_account_id);
    }

    public function test_store_requires_a_settlement_account_for_a_securities_account(): void
    {
        // The end-to-end consequence of the rule: through the real endpoint, a
        // securities account with nowhere to settle is refused outright. The
        // payload is otherwise a working one, so the link is the only thing that
        // can be failing.
        $response = $this->post('/accounts', $this->payload([
            'name' => 'Broker',
            'type' => 'security',
            'meta_data' => [],
        ]));

        $response->assertSessionHasErrors('settlement_account_id');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_store_rejects_a_securities_account_settling_into_a_card(): void
    {
        $card = Account::create(['name' => 'Card B', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);

        $response = $this->post('/accounts', $this->payload([
            'name' => 'Broker',
            'type' => 'security',
            'meta_data' => [],
            'settlement_account_id' => $card->id,
        ]));

        $response->assertSessionHasErrors('settlement_account_id');
        // Only the card that set up the test exists; the brokerage did not land.
        $this->assertNull(Account::firstWhere('name', 'Broker'));
    }

    public function test_store_rejects_a_cash_account_carrying_a_settlement_account(): void
    {
        // This is the prohibition that makes a settlement cycle unrepresentable,
        // so it is worth holding at the endpoint too: it is the hop a cycle would
        // have to close through.
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $response = $this->post('/accounts', $this->payload([
            'name' => 'Second Bank',
            'type' => 'cash',
            'meta_data' => [],
            'settlement_account_id' => $bank->id,
        ]));

        $response->assertSessionHasErrors('settlement_account_id');
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_update_can_move_a_securities_account_to_another_bank(): void
    {
        $one = Account::create(['name' => 'Bank One', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $two = Account::create(['name' => 'Bank Two', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->update(['settlement_account_id' => $one->id]);

        $this->put("/accounts/{$broker->id}", $this->payload([
            'id' => $broker->id,
            'name' => 'Broker',
            'type' => 'security',
            'meta_data' => [],
            'settlement_account_id' => $two->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame($two->id, $broker->fresh()->settlement_account_id);
    }

    public function test_update_refuses_to_drop_the_settlement_link(): void
    {
        // The guard that makes the link safe to require. An absent field
        // hydrates to null and toArray() passes it to update(), so without
        // required_if a save from any form that omits the field -- which is
        // every form today -- would quietly null the link out and leave a
        // brokerage with no cash story. Refusing the save is the loud option;
        // the link survives.
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->update(['settlement_account_id' => $bank->id]);

        $response = $this->put("/accounts/{$broker->id}", $this->payload([
            'id' => $broker->id,
            'name' => 'Renamed',
            'type' => 'security',
            'meta_data' => [],
        ]));

        $response->assertSessionHasErrors('settlement_account_id');
        $this->assertSame($bank->id, $broker->fresh()->settlement_account_id);
        $this->assertSame('Broker', $broker->fresh()->name);
    }

    public function test_update_changes_the_account(): void
    {
        $account = Account::create(['name' => 'Old', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'New',
        ]));

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Account [New] updated');

        $this->assertSame('New', $account->fresh()->name);
    }

    public function test_update_allows_keeping_the_same_name(): void
    {
        // The Unique rule must ignore the model currently being updated.
        $account = Account::create(['name' => 'Same', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'Same',
            'ccy' => 'EUR',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('EUR', $account->fresh()->ccy);
    }

    public function test_update_creates_meta_when_none_existed(): void
    {
        $account = Account::create(['name' => 'NoMeta', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'NoMeta',
        ]));

        $this->assertDatabaseHas('meta', [
            'model_id' => $account->id,
            'model_type' => Account::class,
        ]);
    }

    public function test_update_deletes_meta_when_it_is_blanked(): void
    {
        // NOTE: type must NOT be "card" here. AccountMetaData::rules() applies
        // `required_if:type,card` to `due`, so a card account can never be
        // saved with a blank due date -- validation rejects the request before
        // the controller runs. The meta-deletion branch is only reachable for
        // non-card accounts.
        $account = Account::create(['name' => 'HasMeta', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => 15]]);

        $this->assertDatabaseCount('meta', 1);

        $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'HasMeta',
            'type' => 'cash',
            'meta_data' => ['due' => null, 'statement_day' => null],
        ]));

        $this->assertDatabaseCount('meta', 0);
    }

    public function test_update_cannot_blank_due_on_a_card_account(): void
    {
        // The counterpart to the test above: for type=card the due date is
        // required, so blanking it fails validation and nothing is written.
        // statement_day is supplied so the only error under test is the due day.
        $account = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => 15, 'statement_day' => 25]]);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'Card',
            'type' => 'card',
            'meta_data' => ['due' => null, 'statement_day' => 25],
        ]));

        $response->assertSessionHasErrors('meta_data.due');
        $this->assertDatabaseCount('meta', 1);
    }

    public function test_update_cannot_blank_statement_day_on_a_card_account(): void
    {
        $account = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => 15, 'statement_day' => 25]]);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'Card',
            'type' => 'card',
            'meta_data' => ['due' => 15, 'statement_day' => null],
        ]));

        $response->assertSessionHasErrors('meta_data.statement_day');

        // The original terms must survive the rejected request, not be blanked.
        $this->assertSame(25, $account->fresh()->meta->meta['statement_day']);
    }

    public function test_update_requires_id_in_the_payload(): void
    {
        // Documents a real fragility rather than desired behaviour.
        //
        // AppServiceProvider calls Model::unguard() globally, so every attribute
        // is mass assignable -- including AccountData::$id. An update payload
        // that omits "id" therefore compiles `update accounts set id = NULL`
        // and trips the primary key constraint. The Vue frontend happens to
        // always send it, because useWatchTarget() seeds the form from the
        // whole table row.
        //
        // AccountController wraps its work in DB::beginTransaction() with a
        // catch-all, so the failure surfaces as a 302 carrying the generic
        // "error db..." flash rather than a 500 (CategoryController, which has
        // no such try/catch, returns a 500 for the identical mistake).
        $account = Account::create(['name' => 'Fragile', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'name' => 'Fragile',
        ]));

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'error db...');

        // The transaction was rolled back, so nothing changed.
        $this->assertSame('Fragile', $account->fresh()->name);
    }

    public function test_destroy_removes_the_account_and_its_meta(): void
    {
        $account = Account::create(['name' => 'Doomed', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => 15]]);

        $response = $this->delete("/accounts/{$account->id}");

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Account [Doomed] deleted');

        $this->assertDatabaseCount('accounts', 0);
        $this->assertDatabaseCount('meta', 0);
    }

    public function test_meta_data_accessor_is_appended_to_array_serialization(): void
    {
        $account = Account::create(['name' => 'Serialized', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => 15]]);

        $array = $account->fresh()->toArray();

        // HasMeta overrides getArrayableAppends() to force-append meta_data.
        $this->assertArrayHasKey('meta_data', $array);
        $this->assertSame(['due' => 15], $array['meta_data']);
    }

    public function test_meta_model_stores_json_as_array_object(): void
    {
        $account = Account::create(['name' => 'Casted', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => 15]]);

        $meta = Meta::firstWhere('model_id', $account->id);

        $this->assertNotNull($meta);
        $this->assertSame(15, $meta->meta['due']);
    }
}
