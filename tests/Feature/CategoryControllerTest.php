<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_the_category_inertia_page(): void
    {
        Category::create(['name' => 'Groceries']);

        $response = $this->get('/categories');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('category')
            ->has('formEmpty')
            ->has('data')
            ->has('params')
            ->has('meta')
            ->where('meta.form', 'category-form')
            ->where('meta.path', '/categories')
        );
    }

    public function test_store_creates_the_category(): void
    {
        $response = $this->post('/categories', ['name' => 'Groceries']);

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Category [Groceries] created');

        $this->assertDatabaseHas('categories', ['name' => 'Groceries']);
    }

    public function test_store_requires_a_name(): void
    {
        $response = $this->post('/categories', ['name' => '']);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_store_rejects_a_duplicate_name(): void
    {
        Category::create(['name' => 'Groceries']);

        $response = $this->post('/categories', ['name' => 'Groceries']);

        $response->assertSessionHasErrors('name');
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_update_changes_the_category(): void
    {
        $category = Category::create(['name' => 'Old']);

        $response = $this->put("/categories/{$category->id}", [
            'id' => $category->id,
            'name' => 'New',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Category [New] updated');

        $this->assertSame('New', $category->fresh()->name);
    }

    public function test_update_allows_keeping_the_same_name(): void
    {
        $category = Category::create(['name' => 'Same']);

        $response = $this->put("/categories/{$category->id}", [
            'id' => $category->id,
            'name' => 'Same',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('Same', $category->fresh()->name);
    }

    public function test_update_needs_no_id_in_the_payload(): void
    {
        // The inverse of what this test used to assert. AccountControllerTest has
        // the same history, in the same direction.
        //
        // Ungated, CategoryData::$id was mass assigned onto the model, so omitting
        // "id" compiled `update categories set id = NULL` and a 500 -- a failure
        // CategoryController, having no try/catch, showed in full. With a $fillable
        // allowlist the id is not writable, so the same payload is just a smaller
        // one and the update succeeds.
        $category = Category::create(['name' => 'Fragile']);

        $response = $this->put("/categories/{$category->id}", ['name' => 'Renamed']);

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Category [Renamed] updated');
        $this->assertSame('Renamed', $category->fresh()->name);
    }

    public function test_destroy_removes_the_category(): void
    {
        $category = Category::create(['name' => 'Doomed']);

        $response = $this->delete("/categories/{$category->id}");

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Category [Doomed] deleted');

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_index_says_which_categories_cannot_be_deleted_and_why(): void
    {
        // So the delete button is disabled with the reason on it, rather than asking for
        // a confirmation the server then turns down.
        $account = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $used = Category::create(['name' => 'FOOD']);
        $free = Category::create(['name' => 'SPARE']);

        $this->expenseOn($used, $account);

        $this->get('/categories')->assertInertia(fn (Assert $page) => $page
            ->where("refusals.{$used->id}", fn ($message) => str_starts_with($message, 'Category [FOOD] has 1 transaction'))
            ->missing("refusals.{$free->id}")
        );
    }

    public function test_destroy_refuses_a_category_that_transactions_are_filed_under(): void
    {
        // The one that was a 500. category_id is restrictOnDelete, so this used to
        // raise a QueryException out of the controller: a 500 with the row still
        // there and nothing said about why. Asserted as a redirect carrying a
        // message, because "it did not blow up" is a weaker claim than "it said
        // no" -- and a silent refusal would read the same way from the browser.
        $account = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $category = Category::create(['name' => 'FOOD']);

        $this->expenseOn($category, $account);

        $response = $this->delete("/categories/{$category->id}");

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Category [FOOD] has 1 transaction and cannot be deleted. Rename it, or move '
            .'it to another category first.');
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_destroy_counts_every_transaction_not_just_the_first(): void
    {
        // The count is in the message, so a user told "1 transaction" when two are
        // filed under the category would fix one, hit the same wall, and have no
        // reason to think the number had been counting something else.
        $account = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $category = Category::create(['name' => 'FOOD']);

        $this->expenseOn($category, $account);
        $this->expenseOn($category, $account);

        $this->delete("/categories/{$category->id}")
            ->assertSessionHas('message', 'Category [FOOD] has 2 transactions and cannot be deleted. Rename it, or move '
                .'them to another category first.');
    }

    public function test_destroy_allows_a_category_nothing_is_filed_under(): void
    {
        // The other half, so the guard is not simply refusing everything. The
        // nullable category_id is the trap here: counting with whereNotNull would
        // pass on a category whose transactions were all uncategorised.
        $account = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $category = Category::create(['name' => 'FOOD']);
        $used = Category::create(['name' => 'Used']);

        $this->expenseOn($used, $account);

        Transaction::create([
            'account_id' => $account->id,
            'category_id' => null,
            'date' => '2026-01-11',
            'type' => 'withdraw',
            'description' => 'Uncategorised',
            'amount' => '9.0000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $this->delete("/categories/{$category->id}")
            ->assertSessionHas('message', 'Category [FOOD] deleted');

        $this->assertDatabaseMissing('categories', ['name' => 'FOOD']);
        $this->assertDatabaseHas('categories', ['name' => 'Used']);
    }

    private function expenseOn(Category $category, Account $account): Transaction
    {
        return Transaction::create([
            'account_id' => $account->id,
            'category_id' => $category->id,
            'date' => '2026-01-10',
            'type' => 'withdraw',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);
    }
}
