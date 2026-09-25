<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
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

    public function test_the_active_connection_can_actually_be_reached(): void
    {
        // A cheap round trip proves the guard is not merely pattern-matching a
        // name that happens to be unreachable.
        $this->assertNotNull(
            DB::connection()->getPdo()
        );
    }
}
