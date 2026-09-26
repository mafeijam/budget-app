<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use Database\Seeders\Concerns\GuardsAgainstNonTestDatabase;
use Illuminate\Database\Seeder;

/**
 * Development fixtures: accounts of every type, all in HKD.
 *
 * Run it with an explicit class, not through DatabaseSeeder:
 *
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevAccountSeeder
 *
 * DatabaseSeeder is left alone on purpose -- it seeds placeholder data for a
 * fresh install, so it runs against whatever database is configured, which on
 * this project is the live one.
 *
 * All-HKD is deliberate rather than incidental. A securities account may only
 * settle into a cash account of the same currency, so one currency for the whole
 * set means every pair below clears that guard with no special cases in the
 * seeder. What it gives up is a cross-currency pair to click through; the
 * browser harness covers that instead.
 */
class DevAccountSeeder extends Seeder
{
    use GuardsAgainstNonTestDatabase;

    public function run(): void
    {
        $this->guardAgainstNonTestDatabase(self::class);

        foreach ($this->definitions() as $definition) {
            $account = Account::updateOrCreate(
                ['name' => $definition['name']],
                [
                    'name' => $definition['name'],
                    'status' => $definition['status'],
                    'type' => $definition['type'],
                    'ccy' => Currency::Hkd->value,
                ],
            );

            $meta = $definition['meta'];

            if ($meta === null) {
                $account->meta()->delete();

                continue;
            }

            // Set outright rather than merged, so re-running restores the intended
            // state instead of accumulating whatever an earlier run left behind.
            $account->meta()->updateOrCreate(
                ['model_id' => $account->id, 'model_type' => Account::class],
                ['meta' => $meta],
            );
        }

        // A brokerage can only point at an account that already exists, and the
        // ids are not known until the rows are written, so this is necessarily
        // a second pass. Keyed on the name rather than the id, which is what
        // makes it idempotent.
        //
        // Merged into the existing bag rather than replacing it, so a brokerage
        // that also carried card terms would keep them -- the two sets of
        // attributes are disjoint by rule, but the seeder should not be the thing
        // that decides that.
        foreach ($this->settlements() as $name => $settlesInto) {
            $broker = Account::where('name', $name)->firstOrFail();

            $meta = $broker->fresh()->meta?->meta?->getArrayCopy() ?? [];
            $meta['settlement_account_id'] = Account::where('name', $settlesInto)->value('id');

            $broker->meta()->updateOrCreate(
                ['model_id' => $broker->id, 'model_type' => Account::class],
                ['meta' => $meta],
            );
        }
    }

    /**
     * @return list<array{name: string, type: string, status: string, meta: ?array<string, int>}>
     */
    private function definitions(): array
    {
        return [
            // Two cash accounts so the settlement picker has a real choice to
            // offer rather than exactly one option.
            [
                'name' => 'Dev Cash',
                'type' => AccountType::Cash->value,
                'status' => AccountStatus::Active->value,
                'meta' => null,
            ],
            [
                'name' => 'Dev Cash Reserve',
                'type' => AccountType::Cash->value,
                'status' => AccountStatus::Active->value,
                'meta' => null,
            ],

            // Distinct terms and closing days on purpose: two cards with the same
            // numbers would hide an off-by-one in the statement cycle, because
            // every derived date would still look plausible.
            [
                'name' => 'Dev Card',
                'type' => AccountType::Card->value,
                'status' => AccountStatus::Active->value,
                'meta' => ['term_days' => 15, 'statement_day' => 25],
            ],

            // Inactive, so the accounts table and the edit form's status picker
            // have a value other than 'active' to show. An all-active fixture set
            // cannot surface a bug where a status is dropped on save.
            [
                'name' => 'Dev Card Everyday',
                'type' => AccountType::Card->value,
                'status' => AccountStatus::Inactive->value,
                'meta' => ['term_days' => 28, 'statement_day' => 5],
            ],

            [
                'name' => 'Dev Brokerage',
                'type' => AccountType::Security->value,
                'status' => AccountStatus::Active->value,
                'meta' => null,
            ],
            [
                'name' => 'Dev Brokerage Alt',
                'type' => AccountType::Security->value,
                'status' => AccountStatus::Active->value,
                'meta' => null,
            ],
        ];
    }

    /**
     * Securities account name => the cash account it settles through.
     *
     * @return array<string, string>
     */
    private function settlements(): array
    {
        return [
            'Dev Brokerage' => 'Dev Cash',
            'Dev Brokerage Alt' => 'Dev Cash Reserve',
        ];
    }
}
