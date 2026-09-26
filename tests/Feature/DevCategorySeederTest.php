<?php

namespace Tests\Feature;

use App\Models\Category;
use Database\Seeders\DevCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the dev category fixtures.
 *
 * categories is a single unique name column with no type, so "about ten types"
 * is ten rows and nothing more. The names are taken from the older `budget`
 * database, which is the only surviving record of what this user actually
 * categorised spending under -- inventing plausible-looking names instead would
 * be the wrong kind of convenient.
 *
 * Shares the database guard with DevAccountSeeder, and for the same reason: these
 * are dev fixtures, .env resolves to the live database, and a category seeder
 * that runs there leaves rows a person then has to recognise and delete.
 */
class DevCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_about_ten_categories(): void
    {
        $this->seed(DevCategorySeeder::class);

        $count = Category::count();

        $this->assertGreaterThanOrEqual(10, $count, 'Fewer than the ten categories asked for.');
        $this->assertLessThanOrEqual(12, $count, 'More than the "about ten" asked for.');
    }

    public function test_the_names_are_distinct(): void
    {
        $this->seed(DevCategorySeeder::class);

        // categories.name is unique, so the database would have thrown rather
        // than duplicated. Asserting it anyway would be noise.
        $this->assertSame(
            Category::count(),
            Category::distinct()->count('name'),
            'Duplicate category names.'
        );
    }

    public function test_the_names_are_the_ones_the_user_actually_used(): void
    {
        $this->seed(DevCategorySeeder::class);

        // Every seeded name must exist in the older `budget` database's category
        // list. This is the assertion that makes the fixtures honest rather than
        // invented: a name I made up to fill the quota would fail here.
        $this->assertSame(
            [],
            Category::pluck('name')->diff($this->recoveredNames())->values()->all(),
            'A seeded category name is not one of the names recovered from the old '
            .'`budget` database.'
        );
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DevCategorySeeder::class);
        $count = Category::count();

        $this->seed(DevCategorySeeder::class);
        $this->seed(DevCategorySeeder::class);

        $this->assertSame($count, Category::count());
    }

    public function test_the_categories_page_renders_the_fixtures(): void
    {
        $this->seed(DevCategorySeeder::class);

        // per_page above the fixture count: the index pages at 5 by default and
        // there are ten rows, so without it this measures the paginator.
        $response = $this->get('/categories?per_page=50');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('category')
            ->has('data.data', Category::count())
            // The names, not just a count: a count alone would pass if the page
            // rendered ten rows that were not the ten that were seeded.
            ->where('data.data', function ($rows) {
                $rendered = collect($rows)->pluck('name')->all();
                $seeded = Category::pluck('name')->all();
                sort($rendered);
                sort($seeded);

                return $rendered === $seeded;
            })
        );
    }

    public function test_a_seeded_category_can_be_resaved_through_the_real_endpoint(): void
    {
        $this->seed(DevCategorySeeder::class);

        $category = Category::orderBy('id')->firstOrFail();

        $response = $this->put("/categories/{$category->id}", [
            'id' => $category->id,
            'name' => 'FOOD & DRINK',
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_it_refuses_to_run_against_a_database_that_is_not_a_test_database(): void
    {
        Config::set('database.connections.mysql.database', 'budget_v2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/budget_v2/');

        try {
            $this->seed(DevCategorySeeder::class);
        } finally {
            Config::set('database.connections.mysql.database', 'budget_v2_testing');
        }
    }

    public function test_it_writes_nothing_when_the_guard_refuses(): void
    {
        Config::set('database.connections.mysql.database', 'budget_v2');

        try {
            $this->seed(DevCategorySeeder::class);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, Category::count(), 'A refused seed must not have written a partial set.');
    }

    /**
     * The category names in the older `budget` database, as of 2026-09-26.
     *
     * Hardcoded rather than queried: the whole point of the assertion above is
     * that the seeder matches this list, and a test that read the list from the
     * same place the seeder does would agree with anything.
     *
     * @return list<string>
     */
    private function recoveredNames(): array
    {
        return [
            'BROADBAND',
            'CAMERA',
            'CASH IN',
            'CLOTHING & SHOE',
            'COMPUTER STUFF',
            'DATING',
            'DOG',
            'FOOD & DRINK',
            'GAME & TOY',
            'HOME',
            'LEARNING',
            'LOAN',
            'MOBILE',
            'MOVIE',
            'MUSIC',
            'OCTOPUS',
            'OTHER',
            'SNACK',
            'SOFTWARE',
            'TRAVEL',
            'WEB SERVICE',
        ];
    }
}
