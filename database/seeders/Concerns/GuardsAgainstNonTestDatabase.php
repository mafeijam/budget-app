<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Refuses to write development fixtures into anything but a throwaway database.
 *
 * WHY
 * ---
 * .env sets DB_DATABASE=budget_v2, which is the LIVE database, and that is what
 * `php artisan db:seed` resolves to by default. So a seeder that quietly writes
 * rows puts dev fixtures into real data, and cleaning them up means recognising
 * them by name and deleting them by hand.
 *
 * The check reads the RESOLVED connection rather than the DB_DATABASE env var,
 * for the same reason the test suite does: a cached config
 * (bootstrap/cache/config.php) overrides the environment entirely, so an env-var
 * check would pass while the connection pointed somewhere else.
 *
 * The marker list is the same one Tests\CreatesApplication uses. It is a
 * convention, not proof, and it is a convention on purpose: the alternative --
 * asking the operator to confirm they meant it -- is a prompt that gets
 * dismissed, and a dismissed prompt has cost more data than this project has.
 */
trait GuardsAgainstNonTestDatabase
{
    protected function guardAgainstNonTestDatabase(string $seeder): void
    {
        $connection = Config::get('database.default');
        $name = Config::get("database.connections.{$connection}.database");

        if (! is_string($name) || $name === '' || $name === ':memory:') {
            return;
        }

        $lowered = strtolower($name);

        foreach (['test', 'testing', 'sqlite', 'in_memory', ':memory:'] as $marker) {
            if (str_contains($lowered, $marker)) {
                return;
            }
        }

        throw new RuntimeException(sprintf(
            "Refusing to run %s: the '%s' connection points at '%s', which does not "
            ."look like a test database.\n\n"
            .'These are development fixtures, not data for a real budget, and .env '
            .'resolves DB_DATABASE to your live database. Seeding there leaves rows '
            ."that have to be recognised and deleted by hand.\n\n"
            ."Point it at a throwaway database instead:\n"
            ."    DB_DATABASE=budget_v2_testing php artisan db:seed --class=%s\n",
            class_basename($seeder),
            (string) $connection,
            $name,
            $seeder,
        ));
    }
}
