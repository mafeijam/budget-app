<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the single most destructive failure mode in this repository.
 *
 * The suite uses RefreshDatabase, which runs `migrate:fresh` -- it DROPS every
 * table before recreating it. If the connection ever resolves to the live
 * database, running the tests silently destroys real data.
 *
 * The <env> entries in phpunit.xml are not enough protection on their own:
 * PHPUnit applies them before the framework boots, but a cached config
 * (bootstrap/cache/config.php) takes precedence and bakes in whatever
 * DB_DATABASE was set to at cache time. `php artisan config:cache` followed by
 * `phpunit` therefore points the whole suite at budget_v2.
 *
 * Tests\CreatesApplication::guardAgainstNonTestDatabase() aborts the run in
 * that situation. This class is the always-on canary for the normal case: it
 * asserts the connection that was actually resolved is a test database.
 */
class DatabaseSafetyTest extends TestCase
{
    public function test_the_suite_never_touches_the_live_database(): void
    {
        $name = config('database.connections.'.config('database.default').'.database');

        $this->assertNotNull($name, 'No database is configured for the active connection.');

        $this->assertMatchesRegularExpression(
            '/test|testing|sqlite|:memory:/i',
            (string) $name,
            "The test suite resolved to the '{$name}' database, which does not look "
            .'like a test database. Running RefreshDatabase here would drop live tables. '
            .'If you just ran `php artisan config:cache`, run `php artisan optimize:clear`.'
        );
    }

    /**
     * The suite's database is not the one .env names.
     *
     * The canary above cannot catch this, and did not: it asks whether the name
     * LOOKS like a test database, and for a long while the suite resolved to
     * budget_v2_testing, which very much does. Meanwhile .env named the same
     * database, so the suite and the app shared one set of tables and
     * migrate:fresh destroyed the development fixtures on every run.
     *
     * Two databases that both look like test databases are still one database, and
     * the only thing that distinguishes them is being different -- so this compares
     * them rather than pattern-matching either.
     *
     * Skipped where there is no .env, which is CI: nothing to develop against there,
     * and nothing for a stray env var to be mistaken for.
     */
    public function test_the_suite_does_not_share_a_database_with_the_development_environment(): void
    {
        $env = base_path('.env');

        if (! is_readable($env)) {
            $this->markTestSkipped('No .env to compare against; nothing is being developed here.');
        }

        $development = $this->databaseNameIn((string) file_get_contents($env));
        $resolved = (string) config('database.connections.'.config('database.default').'.database');

        $this->assertNotNull(
            $development,
            'No DB_DATABASE in .env, so there is nothing to compare the suite against. '
            .'If .env has been restructured, this test needs to know where the name went.'
        );

        $this->assertNotSame(
            $development,
            $resolved,
            "The suite and .env both resolve to '{$resolved}'. RefreshDatabase runs "
            .'migrate:fresh, so every test run drops the development tables and the '
            .'seeded data with them. Point <env name="DB_DATABASE"> in phpunit.xml at a '
            .'different database -- the one in .env is the development one.'
        );
    }

    /**
     * The DB_DATABASE .env resolves to, or null if it declares none.
     *
     * Parsed by hand rather than through the framework, because the framework is
     * holding the suite's own value by the time this runs: reading it back out of
     * config() would compare the database against itself and pass.
     */
    private function databaseNameIn(string $env): ?string
    {
        foreach (preg_split('/\R/', $env) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            if ($key !== 'DB_DATABASE') {
                continue;
            }

            $value = trim($value);

            // A quoted value may carry an inline comment after the closing quote;
            // an unquoted one may carry one after a space. Both are stripped.
            $value = (string) preg_replace('/^([\'"])(.*?)\1.*$/', '$2', $value);
            $value = (string) preg_replace('/\s+#.*$/', '', $value);

            return trim($value);
        }

        return null;
    }
}
