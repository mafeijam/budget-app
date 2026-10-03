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
 * The tests assert the reporting through the exception handler, which is the
 * contract the controller controls.
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
            'meta_data' => ['term_days' => 15, 'statement_day' => 25],
        ], $overrides);
    }

    public function test_a_failed_update_is_reported(): void
    {
        // Triggered by an over-long name, the same way test_a_failed_store_is_reported
        // is: accounts.name is varchar(255) and AccountData::rules() only asks for
        // a string, so it passes validation and fails at the database. This used to
        // be triggered by omitting "id", which compiled `update accounts set id =
        // NULL` and tripped the primary key -- but that hole is closed now, so a
        // payload missing the id is simply a smaller payload and no longer a
        // failure at all.
        Exceptions::fake();

        $account = Account::create(['name' => 'Fragile', 'status' => 'active', 'type' => 'card', 'ccy' => 'USD']);

        $response = $this->put("/accounts/{$account->id}", $this->payload([
            'id' => $account->id,
            'name' => str_repeat('x', 300),
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
}
