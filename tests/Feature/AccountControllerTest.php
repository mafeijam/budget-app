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
            'meta_data' => ['due' => '2026-10-01'],
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
        $this->post('/accounts', $this->payload([
            'meta_data' => ['due' => ''],
        ]));

        $this->assertDatabaseCount('meta', 0);
    }

    public function test_store_rejects_a_duplicate_name(): void
    {
        Account::create(['name' => 'Taken', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->post('/accounts', $this->payload(['name' => 'Taken']));

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_store_requires_due_date_when_type_is_card(): void
    {
        $response = $this->post('/accounts', $this->payload([
            'type' => 'card',
            'meta_data' => ['due' => ''],
        ]));

        $response->assertSessionHasErrors('meta_data.due');
        $this->assertDatabaseCount('accounts', 0);
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
            'meta_data' => ['due' => ''],
        ]));

        $this->assertDatabaseCount('meta', 0);
    }

    public function test_update_cannot_blank_due_on_a_card_account(): void
    {
        // The counterpart to the test above: for type=card the due date is
        // required, so blanking it fails validation and nothing is written.
        $account = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);
        $account->meta()->create(['meta' => ['due' => '2026-10-01']]);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'Card',
            'type' => 'card',
            'meta_data' => ['due' => ''],
        ]));

        $response->assertSessionHasErrors('meta_data.due');
        $this->assertDatabaseCount('meta', 1);
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
