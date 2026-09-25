<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->guardAgainstNonTestDatabase($app);

        return $app;
    }

    /**
     * Refuse to run the suite against anything but a throwaway database.
     *
     * WHY THIS EXISTS
     * ---------------
     * The <env> entries in phpunit.xml are applied by PHPUnit *before* the
     * framework boots, so they normally win over .env. They do NOT, however,
     * win over a CACHED config: if bootstrap/cache/config.php exists, the
     * framework reads the values that were baked into it at cache time and
     * never consults the environment again.
     *
     * That combination is dangerous. Running `php artisan config:cache` and
     * then `phpunit` makes DB_DATABASE=budget_v2_testing a no-op, so
     * RefreshDatabase runs `migrate:fresh` against the live database and
     * silently drops every table. That is not hypothetical -- it happened
     * during this upgrade and destroyed live data.
     *
     * So instead of relying on the environment override holding, verify the
     * database that was actually resolved and abort loudly if it is not a
     * test database.
     */
    private function guardAgainstNonTestDatabase(Application $app): void
    {
        // This trait only ever builds the application for the test suite, so
        // the check is unconditional. It deliberately does NOT try to detect a
        // test context from $app->environment() or $app->runningUnitTests():
        // when a config cache is present those report the CACHED values
        // (app.env=baked in as "local", runningUnitTests()=false) even under
        // PHPUnit, so they cannot be trusted here.
        $database = $app['config']->get('database.default');

        if ($database === null) {
            return;
        }

        $name = $app['config']->get("database.connections.{$database}.database");

        if (! is_string($name) || $name === '' || $name === ':memory:') {
            return;
        }

        if ($this->looksLikeTestDatabase($name)) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Refusing to run the test suite: the '%s' connection points at '%s', "
            ."which does not look like a test database.\n\n"
            .'A cached config (bootstrap/cache/config.php) overrides the DB_DATABASE '
            .'value in phpunit.xml, which would make RefreshDatabase run '
            ."`migrate:fresh` against your real data.\n\n"
            ."Fix it with:  php artisan optimize:clear\n"
            .'Or set DB_DATABASE to a dedicated throwaway database in phpunit.xml '
            ."and .env.testing.\n",
            $database,
            $name
        ));
    }

    /**
     * Decide whether a database name is safe to migrate from and drop.
     */
    private function looksLikeTestDatabase(string $name): bool
    {
        $name = strtolower($name);

        foreach (['test', 'testing', 'sqlite', 'in_memory', ':memory:'] as $marker) {
            if (str_contains($name, $marker)) {
                return true;
            }
        }

        return false;
    }
}
