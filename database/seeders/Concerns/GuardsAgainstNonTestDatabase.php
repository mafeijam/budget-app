<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Refuses to write development fixtures into anything but a throwaway database.
 *
 * WHY
 * ---
 * Which database this runs against is a .env setting, and .env is not committed
 * and has already been repointed once -- it used to hold the live database. So
 * the guard deliberately does not describe the current default, because a guard
 * written around a default stops working the moment the default moves. It checks
 * the RESOLVED connection instead: a cached config
 * (bootstrap/cache/config.php) overrides .env entirely, so an env-var check
 * would pass while the connection pointed at live data.
 *
 * The marker list is the same one Tests\CreatesApplication uses. It is a
 * convention, not proof, and that is on purpose: the alternative is a
 * confirmation prompt, and a dismissed prompt has cost this project more data
 * than the convention might.
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
