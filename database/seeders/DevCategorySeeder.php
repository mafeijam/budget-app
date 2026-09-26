<?php

namespace Database\Seeders;

use App\Models\Category;
use Database\Seeders\Concerns\GuardsAgainstNonTestDatabase;
use Illuminate\Database\Seeder;

/**
 * Development fixtures: ten categories.
 *
 * Run it with an explicit class:
 *
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevCategorySeeder
 *
 * `categories` is a single unique name column with no type, so "about ten types"
 * is ten rows and there is nothing to group them by.
 *
 * The names are the real ones. Ten of the 21 categories in the older `budget`
 * database, which is the only surviving record of what this user actually
 * categorised spending under. Inventing plausible-looking names to fill a quota
 * would be the wrong kind of convenient: the point of a dev fixture is to make
 * the thing you are working on look like the thing you have.
 */
class DevCategorySeeder extends Seeder
{
    use GuardsAgainstNonTestDatabase;

    public function run(): void
    {
        $this->guardAgainstNonTestDatabase(self::class);

        foreach ($this->names() as $name) {
            Category::updateOrCreate(['name' => $name], ['name' => $name]);
        }
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return [
            'FOOD & DRINK',
            'TRAVEL',
            'CLOTHING & SHOE',
            'HOME',
            'MOBILE',
            'BROADBAND',
            'MOVIE',
            'MUSIC',
            'LEARNING',

            // Kept last because it is the catch-all, and a dev DB with a
            // catch-all is where you notice a transaction that classifies badly.
            'OTHER',
        ];
    }
}
