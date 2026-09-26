<?php

namespace Tests\Feature;

use App\Models\Category;
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
}
