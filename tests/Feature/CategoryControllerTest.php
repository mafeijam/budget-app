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

    public function test_update_requires_id_in_the_payload(): void
    {
        // See AccountControllerTest::test_update_requires_id_in_the_payload.
        //
        // AppServiceProvider calls Model::unguard() globally, so CategoryData::$id
        // is mass assigned onto the model. Omitting "id" yields
        // `update categories set id = NULL` and a 500. Unlike AccountController,
        // CategoryController has no try/catch, so the error is not swallowed.
        $category = Category::create(['name' => 'Fragile']);

        $response = $this->put("/categories/{$category->id}", ['name' => 'Fragile']);

        $response->assertStatus(500);
        $this->assertSame('Fragile', $category->fresh()->name);
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
