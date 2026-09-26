<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use App\Support\CardStatementCycle;
use Database\Seeders\DevAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the dev account fixtures.
 *
 * A seeder that writes rows straight to the model bypasses AccountData, so
 * nothing validates what it produces. The point of these tests is therefore not
 * that the rows exist but that they are USABLE: a card the app can derive due
 * dates from, a brokerage that resolves to a real cash account, and every row
 * re-savable through the real controller. Rows the edit form rejects are worse
 * than no fixtures, because the breakage shows up later and looks unrelated.
 *
 * The database guard has its own tests here, and they are the reason this file
 * asserts against a non-test name rather than trusting the convention: .env
 * points DB_DATABASE at the live database, so `db:seed --class=DevAccountSeeder`
 * with no override would write fixtures into real data.
 */
class DevAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_at_least_one_account_of_every_type(): void
    {
        $this->seed(DevAccountSeeder::class);

        // Derived from the enum rather than hardcoded, so a fourth account type
        // makes this fail instead of quietly leaving the new one unseeded.
        foreach (AccountType::cases() as $type) {
            $this->assertGreaterThan(
                0,
                Account::where('type', $type->value)->count(),
                "No dev account of type '{$type->value}'. AccountType is the source of "
                .'truth for the allowed set, so every case needs a fixture.'
            );
        }
    }

    public function test_every_account_is_in_hkd(): void
    {
        $this->seed(DevAccountSeeder::class);

        // HKD throughout is what makes the settlement pairs below valid without
        // the seeder having to think about currency parity at all.
        $this->assertSame(
            [],
            Account::where('ccy', '!=', Currency::Hkd->value)->pluck('ccy')->all(),
            'A dev account outside HKD. The fixtures are meant to be all-HKD so that '
            .'every security/cash pair clears the parity guard without special cases.'
        );
    }

    public function test_every_card_carries_a_usable_statement_cycle(): void
    {
        $this->seed(DevAccountSeeder::class);

        $cards = Account::where('type', AccountType::Card->value)->get();

        $this->assertNotEmpty($cards);

        foreach ($cards as $card) {
            $cycle = CardStatementCycle::fromMeta($card->meta?->meta ?? []);

            $this->assertNotNull(
                $cycle,
                "Card [{$card->name}] cannot build a statement cycle, so no charge on it "
                .'will get a due date. Both term_days and statement_day must be numeric.'
            );

            // 1-31 for both, matching AccountMetaData. Out of range, the guard
            // rejects rather than clamps, and the account stops being editable.
            $this->assertContains($cycle->termDays(), range(1, 31));
            $this->assertContains($cycle->statementDay(), range(1, 31));
        }
    }

    public function test_every_security_account_settles_into_a_cash_account_of_the_same_currency(): void
    {
        $this->seed(DevAccountSeeder::class);

        $securities = Account::where('type', AccountType::Security->value)->get();

        $this->assertNotEmpty($securities);

        foreach ($securities as $account) {
            $this->assertNotNull(
                $account->meta?->meta['settlement_account_id'] ?? null,
                "Security account [{$account->name}] has no settlement account. "
                .'AccountMetaData requires the field for this type and prohibits it for every other.'
            );

            $target = $account->settlementAccount();

            $this->assertNotNull($target, "Security account [{$account->name}] settles into a row that does not exist.");

            // Compared as a string: Account declares no casts, so the column comes
            // back as written rather than as the enum.
            $this->assertSame(
                AccountType::Cash->value,
                $target->type,
                'A securities account may only settle through a cash account.'
            );
            $this->assertSame(
                $account->ccy,
                $target->ccy,
                "Security account [{$account->name}] settles into a different currency, which "
                .'AccountData::guardSettlementAccount() refuses at save time.'
            );
        }
    }

    public function test_the_fixtures_cover_both_statuses(): void
    {
        $this->seed(DevAccountSeeder::class);

        // An all-active fixture set cannot show a bug in the status picker: there
        // is no row whose status is anything other than what it was created with.
        foreach (AccountStatus::cases() as $status) {
            $this->assertGreaterThan(
                0,
                Account::where('status', $status->value)->count(),
                "No dev account with status '{$status->value}'."
            );
        }
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DevAccountSeeder::class);
        $count = Account::count();

        $this->seed(DevAccountSeeder::class);
        $this->seed(DevAccountSeeder::class);

        $this->assertSame($count, Account::count());
    }

    public function test_every_seeded_account_can_be_resaved_through_the_real_endpoint(): void
    {
        $this->seed(DevAccountSeeder::class);

        foreach (Account::orderBy('id')->get() as $account) {
            $payload = [
                'id' => $account->id,
                'name' => $account->name,
                'status' => $account->status,
                'type' => $account->type,
                'ccy' => $account->ccy,
            ];

            $meta = $account->meta?->meta->getArrayCopy() ?? [];

            if ($meta !== []) {
                $payload['meta_data'] = $meta;
            }

            $response = $this->put("/accounts/{$account->id}", $payload);

            $response->assertSessionHasNoErrors(
                null,
                "Seeded account [{$account->name}] cannot be re-saved by the real controller. "
                .'A fixture the edit form rejects is worse than none.'
            );
        }
    }

    public function test_the_accounts_page_renders_the_fixtures(): void
    {
        $this->seed(DevAccountSeeder::class);

        // per_page above the fixture count, because the index pages at 5 by
        // default and there are more rows than that. Without it this asserts
        // something about the paginator rather than about the fixtures.
        $response = $this->get('/accounts?per_page=50');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('account')
            ->has('data.data', Account::count())
            // The names, not just a count: a count alone would pass if the page
            // rendered six rows that were not the six that were seeded.
            ->where('data.data', fn ($rows) => $this->names($rows) === $this->names(Account::all()))
        );
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return list<string>
     */
    private function names(iterable $rows): array
    {
        $names = collect($rows)
            ->map(fn ($row) => is_array($row) ? $row['name'] : $row->name)
            ->all();

        sort($names);

        return $names;
    }

    public function test_it_refuses_to_run_against_a_database_that_is_not_a_test_database(): void
    {
        // The safe database is a local, uncommitted .env setting, and it has
        // already been repointed once, so the guard is asserted against any name
        // that does not look like a test database rather than against whatever
        // .env happens to say today.
        Config::set('database.connections.mysql.database', 'budget_v2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/budget_v2/');

        try {
            $this->seed(DevAccountSeeder::class);
        } finally {
            // Never reached on success, so a guard that simply did nothing would
            // leave the connection pointed at the live name for later tests.
            Config::set('database.connections.mysql.database', 'budget_v2_testing');
        }
    }

    public function test_it_writes_nothing_when_the_guard_refuses(): void
    {
        Config::set('database.connections.mysql.database', 'budget_v2');

        try {
            $this->seed(DevAccountSeeder::class);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(0, Account::count(), 'A refused seed must not have written a partial set.');
    }
}
