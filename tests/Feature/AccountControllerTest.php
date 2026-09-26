<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Meta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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
            'meta_data' => ['due' => '2026-10-01', 'statement_day' => 25],
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

        $this->assertSame('2026-10-01', $account->meta->meta['due']);
    }

    public function test_store_omits_the_meta_row_when_meta_data_is_blank(): void
    {
        // type=cash so nothing in meta is required, which is what makes this
        // test meaningful: the account is actually created, and the meta row is
        // absent because the payload was blank rather than because validation
        // rejected the whole request.
        $this->post('/accounts', $this->payload([
            'type' => 'cash',
            'meta_data' => ['due' => '', 'statement_day' => null],
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
            'meta_data' => ['due' => '', 'statement_day' => 25],
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
            'meta_data' => ['due' => '2026-10-01', 'statement_day' => null],
        ]));

        $response->assertSessionHasErrors('meta_data.statement_day');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_store_rejects_a_statement_day_outside_the_calendar(): void
    {
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['due' => '2026-10-01', 'statement_day' => 45],
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
        $account->meta()->create(['meta' => ['due' => '2026-10-01']]);

        $this->assertDatabaseCount('meta', 1);

        $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'HasMeta',
            'type' => 'cash',
            'meta_data' => ['due' => '', 'statement_day' => null],
        ]));

        $this->assertDatabaseCount('meta', 0);
    }

    public function test_update_cannot_blank_due_on_a_card_account(): void
    {
        // The counterpart to the test above: for type=card the due date is
        // required, so blanking it fails validation and nothing is written.
        // statement_day is supplied so the only error under test is the due day.
        $account = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => '2026-10-01', 'statement_day' => 25]]);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'Card',
            'type' => 'card',
            'meta_data' => ['due' => '', 'statement_day' => 25],
        ]));

        $response->assertSessionHasErrors('meta_data.due');
        $this->assertDatabaseCount('meta', 1);
    }

    public function test_update_cannot_blank_statement_day_on_a_card_account(): void
    {
        $account = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => '2026-10-01', 'statement_day' => 25]]);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'Card',
            'type' => 'card',
            'meta_data' => ['due' => '2026-10-01', 'statement_day' => null],
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
        $account->meta()->create(['meta' => ['due' => '2026-10-01']]);

        $response = $this->delete("/accounts/{$account->id}");

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Account [Doomed] deleted');

        $this->assertDatabaseCount('accounts', 0);
        $this->assertDatabaseCount('meta', 0);
    }

    public function test_meta_data_accessor_is_appended_to_array_serialization(): void
    {
        $account = Account::create(['name' => 'Serialized', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => '2026-10-01']]);

        $array = $account->fresh()->toArray();

        // HasMeta overrides getArrayableAppends() to force-append meta_data.
        $this->assertArrayHasKey('meta_data', $array);
        $this->assertSame(['due' => '2026-10-01'], $array['meta_data']);
    }

    public function test_meta_model_stores_json_as_array_object(): void
    {
        $account = Account::create(['name' => 'Casted', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => '2026-10-01']]);

        $meta = Meta::firstWhere('model_id', $account->id);

        $this->assertNotNull($meta);
        $this->assertSame('2026-10-01', $meta->meta['due']);
    }
}
