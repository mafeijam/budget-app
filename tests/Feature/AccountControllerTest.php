<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\Meta;
use App\Support\CardStatementCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'meta_data' => ['term_days' => 15, 'statement_day' => 25],
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

    public function test_index_offers_every_currency_the_app_accepts(): void
    {
        // The currency dropdown's whole option list, and the guarantee that it
        // matches the server rather than a copy of it. The type and status
        // pickers hardcode their options in the template, which cannot offer
        // something AccountData rejects but can fall behind the enum with nothing
        // failing; sending the list closes that second case.
        //
        // Derived from the enum rather than written out, so a case added to
        // Currency fails here instead of quietly appearing in the browser only.
        $expected = collect(Currency::cases())
            ->map(fn (Currency $c) => ['label' => $c->label(), 'value' => $c->value])
            ->all();

        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->component('account')
            ->has('currencyOptions', count($expected))
            ->where('currencyOptions', $expected)
        );
    }

    public function test_index_offers_the_same_currency_options_with_no_accounts_in_existence(): void
    {
        // The list comes from the enum, not from a query, so it must not depend
        // on there being anything to show. An empty accounts table is the state
        // a fresh install is in, and a dropdown that only filled in once an
        // account existed would be empty exactly when it is first needed.
        $this->assertDatabaseCount('accounts', 0);

        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->has('currencyOptions', count(Currency::cases()))
            ->where('currencyOptions.0.value', 'HKD')
        );
    }

    public function test_index_offers_every_account_type_the_app_accepts(): void
    {
        // The type dropdown's whole option list. Previously a literal in the
        // template, so a case added to AccountType was accepted by AccountData
        // and unoffered by the form -- uncreatable through the browser, with
        // every test in this file still green.
        //
        // Derived from the enum rather than written out, so adding a case fails
        // here instead of appearing in the browser only.
        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->component('account')
            ->where('typeOptions', array_column(AccountType::cases(), 'value'))
        );
    }

    public function test_index_offers_every_account_status_the_app_accepts(): void
    {
        // The same, for the status dropdown.
        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->component('account')
            ->where('statusOptions', array_column(AccountStatus::cases(), 'value'))
        );
    }

    public function test_index_offers_the_type_and_status_options_with_no_accounts_in_existence(): void
    {
        // Both lists come from the enum, not from a query, so they must not
        // depend on there being anything to show. An empty accounts table is the
        // state a fresh install is in, and a dropdown that only filled in once an
        // account existed would be empty exactly when it is first needed.
        $this->assertDatabaseCount('accounts', 0);

        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->has('typeOptions', count(AccountType::cases()))
            ->has('statusOptions', count(AccountStatus::cases()))
            ->where('typeOptions.0', 'cash')
            ->where('statusOptions.0', 'active')
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
            'meta_data' => [
                'term_days' => null,
                'statement_day' => null,
                'settlement_account_id' => $bank->id,
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', 'Account [Broker] created');
        $this->assertSame(
            $bank->id,
            (int) Meta::firstWhere('model_id', Account::firstWhere('name', 'Broker')->id)->meta['settlement_account_id']
        );
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
            'meta_data' => [
                'term_days' => 15,
                'statement_day' => 25,
                'settlement_account_id' => null,
            ],
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
            'meta_data' => [
                'term_days' => 15,
                'statement_day' => null,
                'settlement_account_id' => null,
            ],
        ]);

        $response->assertSessionHasErrors('meta_data.statement_day');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_a_date_shaped_term_is_rejected(): void
    {
        // The whole point of retyping `due`. This exact value used to validate
        // and store: the rule was `max:28`, which measured the length of a
        // string, so '2026-10-01' sailed through. CardStatementCycle then
        // refused to build a cycle for it (it requires both card terms to be
        // numeric), so the account saved cleanly and every charge placed on it
        // derived a null due_date. Silent incompleteness, no error anywhere.
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['term_days' => '2026-10-01', 'statement_day' => 25],
        ]));

        $response->assertSessionHasErrors('meta_data.term_days');
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
    public function test_a_term_outside_the_allowed_range_is_rejected(mixed $due): void
    {
        // The old `max:28` accepted all of these. A day of 32 is a data entry
        // error rather than a short month, which is why CardStatementCycle
        // throws on it rather than clamping -- so validation has to agree with
        // the guard instead of quietly accepting what the guard will refuse.
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['term_days' => $due, 'statement_day' => 25],
        ]));

        $response->assertSessionHasErrors('meta_data.term_days');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_the_term_is_stored_as_a_number(): void
    {
        // Meta is JSON, so '15' and 15 are different values and the distinction
        // survives the round trip. CardStatementCycle casts with is_numeric so
        // it tolerates either, but storing the string form is how the field
        // became ambiguous in the first place.
        $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['term_days' => 15, 'statement_day' => 25],
        ]))->assertSessionHasNoErrors();

        $meta = Meta::firstWhere('model_id', Account::firstWhere('name', 'Test Account')->id);

        $this->assertSame(15, $meta->meta['term_days']);
        $this->assertIsInt($meta->meta['term_days']);
    }

    public function test_a_card_built_from_a_stored_payment_term_derives_a_due_date(): void
    {
        // The end of the ambiguity: a card that saves a payment term is now
        // guaranteed to place its charges in a statement period. Before the
        // retype, a date-shaped value produced an account that looked complete
        // and derived nothing.
        Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $card = Account::firstWhere('name', 'Card');
        $card->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);

        $cycle = CardStatementCycle::fromMeta($card->fresh()->meta->meta);

        $this->assertNotNull($cycle);
        $this->assertSame(15, $cycle->termDays());
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

        $this->assertSame(15, $account->meta->meta['term_days']);
    }

    public function test_store_omits_the_meta_row_when_meta_data_is_blank(): void
    {
        // type=cash so nothing in meta is required, which is what makes this
        // test meaningful: the account is actually created, and the meta row is
        // absent because the payload was blank rather than because validation
        // rejected the whole request.
        $this->post('/accounts', $this->payload([
            'type' => 'cash',
            'meta_data' => ['term_days' => null, 'statement_day' => null],
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
            'meta_data' => ['term_days' => null, 'statement_day' => 25],
        ]));

        $response->assertSessionHasErrors('meta_data.term_days');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_store_requires_a_statement_day_when_type_is_card(): void
    {
        // A card with a payment term but no statement day cannot be settled against:
        // nothing says which statement the charge belongs to. Rejecting it at
        // the door is the point -- the alternative is an account that saves
        // cleanly and then silently derives no due date for any charge on it.
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['term_days' => 15, 'statement_day' => null],
        ]));

        $response->assertSessionHasErrors('meta_data.statement_day');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_store_rejects_a_statement_day_outside_the_calendar(): void
    {
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['term_days' => 15, 'statement_day' => 45],
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
            // The bank is HKD, so the brokerage has to be HKD too. Left to the
            // payload default of USD this account is refused, and the test would
            // fail for the currency rather than for the settlement link it is
            // named for.
            'ccy' => 'HKD',
            'meta_data' => ['settlement_account_id' => $bank->id],
        ]));

        $broker = Account::firstWhere('name', 'Broker');

        $this->assertNotNull($broker);
        $this->assertSame($bank->id, (int) $broker->fresh()->meta->meta['settlement_account_id']);
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

        $response->assertSessionHasErrors('meta_data.settlement_account_id');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_store_rejects_a_securities_account_settling_into_another_currency(): void
    {
        // The end-to-end consequence of the parity guard. Asserted here as well
        // as at the DTO level because this is the shape a user meets: the payload
        // is otherwise working, so the currency is the only thing that can fail,
        // and the row must not appear.
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'JPY']);

        $response = $this->post('/accounts', $this->payload([
            'name' => 'Broker',
            'type' => 'security',
            'ccy' => 'HKD',
            'meta_data' => ['settlement_account_id' => $bank->id],
        ]));

        $response->assertSessionHasErrors([
            'meta_data.settlement_account_id' => 'A HKD brokerage cannot settle into a JPY account.',
        ]);
        $this->assertDatabaseMissing('accounts', ['name' => 'Broker']);
    }

    public function test_update_rejects_moving_a_brokerage_into_another_currency(): void
    {
        // The same pairing, arrived at by editing rather than creating: a brokerage
        // that is valid today can be made invalid by moving its settlement link,
        // and that is the more likely way to meet this since the change is a
        // one-field edit to an account the user already has.
        $bank = Account::create(['name' => 'Yen Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'JPY']);
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);

        $response = $this->put("/accounts/{$broker->id}", $this->payload([
            'id' => $broker->id,
            'name' => 'Broker',
            'type' => 'security',
            'ccy' => 'HKD',
            'meta_data' => ['settlement_account_id' => $bank->id],
        ]));

        $response->assertSessionHasErrors('meta_data.settlement_account_id');

        // Unchanged, not just rejected: a refused update that still moved the link
        // would report an error and save anyway.
        $this->assertNull($broker->fresh()->meta);
    }

    public function test_store_rejects_a_securities_account_settling_into_a_card(): void
    {
        $card = Account::create(['name' => 'Card B', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);

        $response = $this->post('/accounts', $this->payload([
            'name' => 'Broker',
            'type' => 'security',
            'meta_data' => ['settlement_account_id' => $card->id],
        ]));

        $response->assertSessionHasErrors('meta_data.settlement_account_id');
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
            'meta_data' => ['settlement_account_id' => $bank->id],
        ]));

        $response->assertSessionHasErrors('meta_data.settlement_account_id');
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_update_can_move_a_securities_account_to_another_bank(): void
    {
        $one = Account::create(['name' => 'Bank One', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $two = Account::create(['name' => 'Bank Two', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $one->id]]);

        $this->put("/accounts/{$broker->id}", $this->payload([
            'id' => $broker->id,
            'name' => 'Broker',
            'type' => 'security',
            // Restated, because this is a full replace rather than a patch: the
            // payload's USD default would move the brokerage out of HKD and out
            // of both banks' currency at once, and the update would be refused.
            'ccy' => 'HKD',
            'meta_data' => ['settlement_account_id' => $two->id],
        ]))->assertSessionHasNoErrors();

        $this->assertSame($two->id, (int) $broker->fresh()->meta->meta['settlement_account_id']);
    }

    public function test_update_refuses_to_drop_the_settlement_link(): void
    {
        // The guard that makes the link safe to require. An absent field
        // hydrates to null and all() passes it on, so without required_if a save
        // from any form that omits the field -- which is every form today --
        // would quietly null the link out and leave a brokerage with no cash
        // story. Refusing the save is the loud option; the link survives.
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $bank->id]]);

        $response = $this->put("/accounts/{$broker->id}", $this->payload([
            'id' => $broker->id,
            'name' => 'Renamed',
            'type' => 'security',
            'meta_data' => [],
        ]));

        $response->assertSessionHasErrors('meta_data.settlement_account_id');
        $this->assertSame($bank->id, (int) $broker->fresh()->meta->meta['settlement_account_id']);
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
            // A different currency to the account's own, so the assertion is
            // about the Unique rule and not about the value happening to match.
            'ccy' => 'CNY',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('CNY', $account->fresh()->ccy);
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
        $account->meta()->create(['meta' => ['term_days' => 15]]);

        $this->assertDatabaseCount('meta', 1);

        $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'HasMeta',
            'type' => 'cash',
            'meta_data' => ['term_days' => null, 'statement_day' => null],
        ]));

        $this->assertDatabaseCount('meta', 0);
    }

    public function test_update_cannot_blank_the_term_on_a_card_account(): void
    {
        // The counterpart to the test above: for type=card the due date is
        // required, so blanking it fails validation and nothing is written.
        // statement_day is supplied so the only error under test is the payment term.
        $account = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'Card',
            'type' => 'card',
            'meta_data' => ['term_days' => null, 'statement_day' => 25],
        ]));

        $response->assertSessionHasErrors('meta_data.term_days');
        $this->assertDatabaseCount('meta', 1);
    }

    public function test_update_cannot_blank_statement_day_on_a_card_account(): void
    {
        $account = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'Card',
            'type' => 'card',
            'meta_data' => ['term_days' => 15, 'statement_day' => null],
        ]));

        $response->assertSessionHasErrors('meta_data.statement_day');

        // The original terms must survive the rejected request, not be blanked.
        $this->assertSame(25, $account->fresh()->meta->meta['statement_day']);
    }

    public function test_update_ignores_an_id_in_the_payload(): void
    {
        // AccountData carries an `id`, because the edit form round-trips the whole
        // table row, and the controller hands that DTO straight to update(). So
        // the id in the payload is a number the client chose. Renumbering a row
        // onto an id another account already holds fails on the primary key, and
        // renumbering it onto a free one succeeds -- which is a row that has moved
        // house with nothing recording that it did.
        $target = Account::create(['name' => 'Target', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account = Account::create(['name' => 'Moving', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $target->id,
            'name' => 'Renamed',
        ]));

        // The success flash, not just the absence of errors: a request that threw
        // would leave the row untouched, which is also what "the id was ignored"
        // looks like, and the two must not be confused.
        $response->assertSessionHas('message', 'Account [Renamed] updated');

        $fresh = $account->fresh();

        $this->assertSame('Renamed', $fresh->name);
        $this->assertNotSame($target->id, $fresh->id, 'The row took the id the payload named.');
        $this->assertSame($account->id, $fresh->id);
        $this->assertDatabaseHas('accounts', ['id' => $target->id, 'name' => 'Target']);
    }

    public function test_update_ignores_a_created_at_in_the_payload(): void
    {
        // The DTO also carries created_at and assigns it a default of now(), so it
        // reads as server-owned; ungated mass assignment let the client overrule
        // that. Backdating a row files it into the wrong period in every list
        // sorted by the column, and the form does not even offer the field.
        $account = Account::create(['name' => 'Stamped', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $original = $account->created_at;

        // DATE_ATOM, because that is what config/data.php casts with. A payload in
        // any other format raises CannotCastDate, which AccountController's
        // catch-all turns into the same generic flash a database failure produces
        // -- so a malformed value here would leave the row untouched and this test
        // green, for a reason that has nothing to do with mass assignment.
        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'Stamped',
            'created_at' => now()->subYears(5)->toAtomString(),
        ]));

        // Asserted so the above cannot recur: an error flash means the row was
        // never written, which is also what "the payload was ignored" looks like.
        $response->assertSessionHas('message', 'Account [Stamped] updated');

        $this->assertTrue(
            $account->fresh()->created_at->equalTo($original),
            'created_at was rewritten from the payload.'
        );
    }

    public function test_update_needs_no_id_in_the_payload(): void
    {
        // The inverse of the fragility this file used to document. Ungated, an
        // update payload without an id compiled `update accounts set id = null`
        // and tripped the primary key, which surfaced as a generic "error db..."
        // 302 -- and the Vue form only ever avoided it by accident, because
        // useWatchTarget() seeds the form from the whole table row. Omitting a
        // field the client may not set is now simply a smaller payload.
        $account = Account::create(['name' => 'Slim', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'name' => 'Slim Renamed',
        ]));

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Account [Slim Renamed] updated');
        $this->assertSame('Slim Renamed', $account->fresh()->name);
    }

    public function test_destroy_refuses_an_account_that_has_transactions(): void
    {
        // The transactions_account_id_foreign is restrict-on-delete, and while the
        // table was empty the restriction could never fire -- so destroy() had no
        // guard for it and the first row written would turn deleting an account
        // with history into a QueryException and a 500. The account list is the
        // natural place to delete one, so this is reachable by accident.
        //
        // Refused rather than cascaded, for the reason the settlement guard gives:
        // deleting a user's financial history because they tidied up a dormant
        // account is not a thing to do, and no amount of theming it better makes
        // it right.
        $account = Account::create(['name' => 'Active', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        DB::table('transactions')->insert([
            'account_id' => $account->id,
            'date' => '2026-01-10',
            'type' => 'expense',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $response = $this->delete("/accounts/{$account->id}");

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Account [Active] has 1 transaction and cannot be deleted. Set it to inactive '
            .'instead, which keeps its history and hides it from new transactions.');
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_destroy_allows_an_account_whose_transactions_are_gone(): void
    {
        // The other half, so the guard is not simply refusing everything: an
        // account with no history is deletable exactly as before.
        $account = Account::create(['name' => 'Spent', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $response = $this->delete("/accounts/{$account->id}");

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Account [Spent] deleted');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_destroy_prefers_the_transaction_refusal_over_the_settlement_one(): void
    {
        // Both guards can fire at once: a bank that a brokerage settles into and
        // that also holds a client's spending. One message has to be chosen, and
        // naming only the settlement link would report a bank with two problems as
        // having one -- leaving the user to fix it and hit the other.
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $bank->id]]);

        DB::table('transactions')->insert([
            'account_id' => $bank->id,
            'date' => '2026-01-10',
            'type' => 'expense',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $this->delete("/accounts/{$bank->id}")
            ->assertSessionHas('message', 'Account [Bank] has 1 transaction and cannot be deleted. Set it to inactive '
                .'instead, which keeps its history and hides it from new transactions.');

        $this->assertDatabaseHas('accounts', ['id' => $bank->id]);
    }

    public function test_index_says_which_accounts_cannot_be_deleted_and_why(): void
    {
        // Sent so the delete button is disabled with the reason on it, rather than asking
        // for a confirmation the server then refuses -- and from the same method as
        // destroy(), so the tooltip and the refusal are one sentence.
        $used = Account::create(['name' => 'Used', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $card = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $card->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25, 'settlement_account_id' => $bank->id]]);
        $free = Account::create(['name' => 'Free', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        DB::table('transactions')->insert([
            'account_id' => $used->id,
            'date' => '2026-01-10',
            'type' => 'expense',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $this->get('/accounts?per_page=10')->assertInertia(fn (Assert $page) => $page
            ->where("refusals.{$used->id}", fn ($message) => str_starts_with($message, 'Account [Used] has 1 transaction'))
            ->where("refusals.{$bank->id}", fn ($message) => str_contains($message, 'settlement account for [Card]'))
            ->missing("refusals.{$free->id}")
            ->missing("refusals.{$card->id}")
        );
    }

    public function test_destroy_removes_the_account_and_its_meta(): void
    {
        $account = Account::create(['name' => 'Doomed', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['term_days' => 15]]);

        $response = $this->delete("/accounts/{$account->id}");

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Account [Doomed] deleted');

        $this->assertDatabaseCount('accounts', 0);
        $this->assertDatabaseCount('meta', 0);
    }

    public function test_meta_data_accessor_is_appended_to_array_serialization(): void
    {
        $account = Account::create(['name' => 'Serialized', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['term_days' => 15]]);

        $array = $account->fresh()->toArray();

        // HasMeta overrides getArrayableAppends() to force-append meta_data.
        $this->assertArrayHasKey('meta_data', $array);
        $this->assertSame(['term_days' => 15], $array['meta_data']);
    }

    public function test_meta_model_stores_json_as_array_object(): void
    {
        $account = Account::create(['name' => 'Casted', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['term_days' => 15]]);

        $meta = Meta::firstWhere('model_id', $account->id);

        $this->assertNotNull($meta);
        $this->assertSame(15, $meta->meta['term_days']);
    }
}
