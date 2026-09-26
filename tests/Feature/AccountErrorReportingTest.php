<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

/**
 * AccountController wraps store() and update() in DB::beginTransaction() with a
 * catch-all that rolls back and returns a generic flash:
 *
 *     } catch (Exception $e) {
 *         DB::rollBack();
 *         return back()->with('message', 'error db...');
 *     }
 *
 * That is a reasonable place to catch -- a failed multi-write really should
 * not half-apply -- but the caught exception used to be discarded unread.
 * Every database failure therefore looked identical from the outside: a 302,
 * the string "error db...", and no record anywhere of what actually went wrong.
 * Support had nothing to go on but the user's description of the form they
 * were filling in.
 *
 * The behaviour under test is that the exception is now reported, so it lands
 * in the log like any other error, while the user-facing response is unchanged.
 * Tests 1-3 assert the reporting through the exception handler, which is the
 * contract the controller controls. test_the_exception_actually_reaches_the_log
 * asserts the end of that pipeline, because "reported" would be worthless if
 * the handler quietly dropped it.
 */
class AccountErrorReportingTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Fine',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'USD',
            'meta_data' => ['due' => 15, 'statement_day' => 25],
        ], $overrides);
    }

    public function test_a_failed_update_is_reported(): void
    {
        // AppServiceProvider calls Model::unguard() globally, so omitting "id"
        // from the payload compiles `update accounts set id = NULL` and trips
        // the primary key. The Vue client always sends it, so this is the
        // shape of failure a user hits only by way of a stale client or a
        // hand-crafted request -- exactly the kind that must be diagnosable.
        Exceptions::fake();

        $account = Account::create(['name' => 'Fragile', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'name' => 'Fragile',
        ]));

        // Unchanged user-facing behaviour.
        $response->assertStatus(302);
        $response->assertSessionHas('message', 'error db...');
        $this->assertSame('Fragile', $account->fresh()->name, 'The transaction must be rolled back.');

        // New: the cause is no longer discarded.
        Exceptions::assertReported(QueryException::class);
    }

    public function test_a_failed_store_is_reported(): void
    {
        // The same catch-all guards store(). accounts.name is varchar(255) but
        // AccountData::rules() only requires a string, so an over-long name
        // passes validation and fails at the database instead.
        Exceptions::fake();

        $response = $this->post('/accounts', $this->payload([
            'name' => str_repeat('x', 300),
        ]));

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'error db...');
        $this->assertDatabaseCount('accounts', 0);

        Exceptions::assertReported(QueryException::class);
    }

    public function test_a_successful_update_reports_nothing(): void
    {
        // The other half of the contract: reporting must not fire on the happy
        // path, or the log fills with noise and the real failures stop standing
        // out.
        Exceptions::fake();

        $account = Account::create(['name' => 'Healthy', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => 'Healthy',
        ]));

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', 'Account [Healthy] updated');

        Exceptions::assertNotReported(QueryException::class);
    }

    public function test_the_exception_actually_reaches_the_log(): void
    {
        // Ends-to-end: report() routes through App\Exceptions\Handler, which
        // registers an empty reportable() callback. That callback returns null
        // rather than false, so reporting continues to the default logger --
        // but only because of that detail, which is worth pinning down here.
        // If someone ever makes the callback return false, or adds the
        // exception to $dontReport, this fails.
        $log = storage_path('logs/laravel-testing-error-reporting.log');

        config()->set('logging.default', 'single');
        config()->set('logging.channels.single.path', $log);

        if (is_file($log)) {
            unlink($log);
        }

        $account = Account::create(['name' => 'Logged', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $this->put("/accounts/{$account->id}", $this->payload([
            'name' => 'Logged',
        ]))->assertSessionHas('message', 'error db...');

        $this->assertFileExists($log, 'The swallowed exception produced no log file at all.');

        $contents = file_get_contents($log);

        $this->assertStringContainsString('QueryException', $contents);
        // The database-level cause, not just the wrapper.
        $this->assertMatchesRegularExpression(
            '/(Column .id. cannot be null|Integrity constraint violation|SQLSTATE)/i',
            $contents,
            'The log entry should identify the underlying SQL error.'
        );

        unlink($log);
    }
}
