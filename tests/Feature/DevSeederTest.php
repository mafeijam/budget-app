<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Database\Seeders\DevAccountSeeder;
use Database\Seeders\DevCategorySeeder;
use Database\Seeders\DevHistorySeeder;
use Database\Seeders\DevRecurringSeeder;
use Database\Seeders\DevTradingSeeder;
use Database\Seeders\DevTransactionSeeder;
use Database\Seeders\DevUsdTradingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the dev fixtures as a set: that each refuses a database without a test
 * marker, that each names what it is missing, and that the README's order seeds
 * a database every page can render and that survives running any of them again.
 *
 * What each fixture contains is not asserted. A fixture that drifts is seen by
 * whoever uses it; the guard is the part whose failure is silent, because .env
 * resolves DB_DATABASE to real data and a seed there writes into it.
 */
class DevSeederTest extends TestCase
{
    use RefreshDatabase;

    /** The README's order; each is idempotent on its own. */
    private const SEEDERS = [
        DevCategorySeeder::class,
        DevAccountSeeder::class,
        DevTransactionSeeder::class,
        DevRecurringSeeder::class,
        DevHistorySeeder::class,
        DevTradingSeeder::class,
        DevUsdTradingSeeder::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-29 12:00:00');
    }

    public static function seeders(): array
    {
        return array_combine(
            array_map('class_basename', self::SEEDERS),
            array_map(fn (string $seeder) => [$seeder], self::SEEDERS),
        );
    }

    #[DataProvider('seeders')]
    public function test_it_refuses_a_database_that_does_not_look_like_a_test_one(string $seeder): void
    {
        $name = Config::get('database.connections.mysql.database');
        Config::set('database.connections.mysql.database', 'budget_v2');

        try {
            $this->seed($seeder);
            $this->fail("{$seeder} ran against budget_v2.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to run '.class_basename($seeder), $e->getMessage());
            $this->assertStringContainsString('budget_v2', $e->getMessage());
        } finally {
            Config::set('database.connections.mysql.database', $name);
        }

        $this->assertSame(0, Account::count() + Category::count() + Transaction::count());
    }

    public static function missingPrerequisites(): array
    {
        return [
            'transactions without accounts' => [DevTransactionSeeder::class, [DevCategorySeeder::class], 'needs the dev accounts'],
            'transactions without categories' => [DevTransactionSeeder::class, [DevAccountSeeder::class], 'needs the dev categories'],
            'recurring without accounts' => [DevRecurringSeeder::class, [DevCategorySeeder::class], 'needs the dev accounts'],
            'recurring without categories' => [DevRecurringSeeder::class, [DevAccountSeeder::class], 'needs the dev categories'],
            'history without categories' => [DevHistorySeeder::class, [], 'needs the dev categories'],
            'trading without the history bank' => [DevTradingSeeder::class, [DevCategorySeeder::class], 'needs the history bank'],
        ];
    }

    #[DataProvider('missingPrerequisites')]
    public function test_it_names_what_it_needs_before_writing(string $seeder, array $first, string $message): void
    {
        foreach ($first as $prerequisite) {
            $this->seed($prerequisite);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->seed($seeder);
    }

    public function test_the_set_seeds_every_page_and_running_any_one_again_doubles_nothing(): void
    {
        foreach (self::SEEDERS as $seeder) {
            $this->seed($seeder);
        }

        $counts = $this->counts();

        // One at a time rather than the whole set again: a later seeder re-run
        // would put back what an earlier one wrongly deleted, and hide it.
        foreach (self::SEEDERS as $seeder) {
            $this->seed($seeder);
            $this->assertSame($counts, $this->counts(), 'Running '.class_basename($seeder).' again changed the rows.');
        }

        foreach (['/', '/accounts', '/transactions', '/categories', '/recurring', '/positions', '/dividends', '/forecast', '/cash-flow', '/net-worth', '/review'] as $page) {
            $this->get($page)->assertOk();
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'accounts' => Account::count(),
            'categories' => Category::count(),
            'transactions' => Transaction::count(),
            'recurring' => RecurringTransaction::count(),
        ];
    }
}
