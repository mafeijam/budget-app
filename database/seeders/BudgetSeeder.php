<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Placeholder data so the app is usable on a fresh install.
 *
 * PROVENANCE -- read before editing
 * ---------------------------------
 * These values are INVENTED. On 2026-09-26 the budget_v2 database lost its
 * contents (2 accounts, 2 categories, 1 meta row) to a `migrate:fresh` that was
 * run against production. A full sweep of the MySQL binary logs (all 33 files,
 * 2026-08-27 to 2026-09-26, binlog_format=ROW with binlog_row_image=FULL)
 * recovered nothing: the table appears only in DDL, never in a row event, so
 * the data predates log retention. The general and slow query logs were both
 * OFF. The older `budget` database holds candidate names but none of the
 * columns budget_v2 actually has, and no `due` value anywhere.
 *
 * So the NAMES below are borrowed from that old `budget` database on the
 * theory that they are what was here before. Everything else -- status, type,
 * currency, and the card's statement day and payment term -- is a guess. Treat
 * all of it as placeholder: correct it through the UI rather than trusting it.
 *
 * The seeder is idempotent (keyed on the unique name columns) so it can be
 * re-run without duplicating rows, and it deliberately reproduces the shape of
 * what was lost: two accounts, two categories, one meta row on the card.
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
                // AccountController::store/update build the meta payload with
                // collect(...)->filter(), which drops falsy values, and
                // AccountMetaData requires both `due` and `statement_day`
                // whenever type is card. A card seeded without them would
                // therefore fail validation the moment it is opened in the edit
                // form. A payment term on its own is not enough either: it is an
                // interval counted from the closing day, so it cannot say which
                // statement a charge belongs to, and no charge on this account
                // would get a due date.
                //
                // Closing on the 25th and payable 15 days later, so this
                // statement falls due on 9 October.
                'meta' => ['due' => 15, 'statement_day' => 25],
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
