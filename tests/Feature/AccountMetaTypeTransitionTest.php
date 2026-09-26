<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers every direction a user can move an account's type in, so the meta
 * row's fate is pinned down for all of them.
 *
 * AccountController::update() branches on whether the submitted meta is
 * non-empty:
 *
 *   non-empty -> $account->meta()->updateOrCreate(['id' => $account->meta?->id], ...)
 *   empty     -> $account->meta()->delete()
 *
 * The updateOrCreate call matches on the meta row's own primary key, which is
 * null when the account has no meta yet. That looks alarming -- `id is null`
 * can never match a primary key, and the generated INSERT carries an explicit
 * `id => NULL` -- but it is harmless in both directions, and these tests exist
 * to say so explicitly rather than leave it as folklore:
 *
 *   - a null id means "no row to match", so Eloquent inserts, which is the
 *     right outcome;
 *   - MySQL assigns the next AUTO_INCREMENT value when a NULL is supplied
 *     explicitly, so the insert does not violate the primary key.
 *
 * The consequence worth knowing: a type change cash -> card does not attempt
 * to update an existing row, it inserts the account's first meta row. The
 * unique index on (model_id, model_type) still holds because only one row ever
 * exists for the account.
 */
class AccountMetaTypeTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $type, bool $withMeta): Account
    {
        $account = Account::create([
            'name' => 'Subject',
            'status' => 'active',
            'type' => $type,
            'ccy' => 'HKD',
        ]);

        if ($withMeta) {
            $account->meta()->create(['meta' => ['due' => '5', 'statement_day' => 5]]);
        }

        return $account;
    }

    public function test_cash_to_card_creates_the_first_meta_row(): void
    {
        $account = $this->account('cash', withMeta: false);

        $response = $this->put("/accounts/{$account->id}", [
            'id' => $account->id,
            'name' => 'Subject',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
            'meta_data' => ['due' => '20', 'statement_day' => 20],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', 'Account [Subject] updated');

        $fresh = $account->fresh();

        $this->assertSame('card', $fresh->type);
        $this->assertDatabaseCount('meta', 1);
        $this->assertSame('20', (string) $fresh->meta->meta['due']);

        // The row must have been given a real primary key by MySQL, not left null.
        $this->assertNotNull($fresh->meta->id);
    }

    public function test_card_to_cash_deletes_the_meta_row(): void
    {
        $account = $this->account('card', withMeta: true);

        $this->assertDatabaseCount('meta', 1);

        $response = $this->put("/accounts/{$account->id}", [
            'id' => $account->id,
            'name' => 'Subject',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'HKD',
            'meta_data' => ['due' => null],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertSame('cash', $account->fresh()->type);
        $this->assertDatabaseCount('meta', 0);
    }

    public function test_card_to_card_updates_the_existing_meta_in_place(): void
    {
        // The branch that the null-id guard does NOT apply to: here
        // $account->meta?->id is a real value, so the existing row is matched
        // and updated rather than a second row being inserted.
        $account = $this->account('card', withMeta: true);
        $originalId = $account->fresh()->meta->id;

        $response = $this->put("/accounts/{$account->id}", [
            'id' => $account->id,
            'name' => 'Subject',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
            'meta_data' => ['due' => '25', 'statement_day' => 25],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseCount('meta', 1);
        $this->assertSame($originalId, $account->fresh()->meta->id, 'Meta row must be updated, not replaced.');
        $this->assertSame('25', (string) $account->fresh()->meta->meta['due']);
    }

    public function test_cash_to_cash_with_no_meta_stays_empty(): void
    {
        $account = $this->account('cash', withMeta: false);

        $response = $this->put("/accounts/{$account->id}", [
            'id' => $account->id,
            'name' => 'Subject',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'HKD',
            'meta_data' => ['due' => null],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseCount('meta', 0);
    }

    public function test_meta_stays_deleted_after_saving_a_cash_account_again(): void
    {
        // Regression guard for the delete branch: delete() on an account with
        // no meta must be a no-op, and must not resurrect the row on a
        // subsequent save of the same non-card account.
        $account = $this->account('card', withMeta: true);

        $this->put("/accounts/{$account->id}", [
            'id' => $account->id,
            'name' => 'Subject',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'HKD',
            'meta_data' => ['due' => null],
        ]);

        $this->assertDatabaseCount('meta', 0);

        $this->put("/accounts/{$account->id}", [
            'id' => $account->id,
            'name' => 'Subject',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'HKD',
            'meta_data' => ['due' => null],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('meta', 0);
    }
}
