<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Placeholder data so the app is usable on a fresh install.
 *
 * INVENTED, not recovered. The names are borrowed from the older `budget`
 * database; everything else is a guess. Correct it through the UI rather than
 * trusting it.
 *
 * Idempotent, keyed on the unique name columns, so re-running does not
 * duplicate rows.
 */
class BudgetSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'FOOD & DRINK'],
            ['name' => 'TRAVEL'],
        ] as $category) {
            Category::updateOrCreate(
                ['name' => $category['name']],
                ['name' => $category['name']],
            );
        }

        foreach ([
            [
                'name' => 'SAVING',
                'status' => 'active',
                'type' => 'cash',
                'ccy' => 'HKD',
                'meta' => null,
            ],
            [
                'name' => 'ADVANCE',
                'status' => 'active',
                'type' => 'card',
                'ccy' => 'HKD',
                // Both are required: `required_if:type,card` means a card seeded
                // without them fails validation the moment it is opened in the edit
                // form, and a term alone places no charge in a statement period.
                // Keep both non-zero -- AccountController's collect()->filter()
                // drops falsy meta, which would delete them on the next save.
                'meta' => ['term_days' => 15, 'statement_day' => 25],
            ],
        ] as $attributes) {
            $meta = $attributes['meta'];
            unset($attributes['meta']);

            $account = Account::updateOrCreate(
                ['name' => $attributes['name']],
                $attributes,
            );

            if ($meta === null) {
                $account->meta()->delete();

                continue;
            }

            // Keyed on the morph pair rather than the id, so this is correct
            // whether or not the account already had a meta row.
            $account->meta()->updateOrCreate(
                ['model_id' => $account->id, 'model_type' => Account::class],
                ['meta' => $meta],
            );
        }
    }
}
