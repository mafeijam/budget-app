<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Meta;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the Meta <-> owner polymorphic relation.
 *
 * Meta::metable() shipped without a `return` statement, so it always evaluated
 * to null. Nothing called it, so the bug was invisible -- but the relation was
 * simply unusable. These tests exist so it cannot silently regress again, and
 * because the relation is the only place the two halves of HasMeta are joined
 * up: HasMeta::meta() defines the morphOne forward direction, metable()
 * defines the morphTo inverse.
 *
 * Both Account and Transaction carry a bag, so both are covered. A transaction's
 * bag is where its due date and card amount live, and without the trait on the model
 * those fields would have nowhere to be read from or written to.
 */
class MetaRelationTest extends TestCase
{
    use RefreshDatabase;

    private function accountWithMeta(array $meta = ['term_days' => 15]): Account
    {
        $account = Account::create([
            'name' => 'Alpha',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'USD',
        ]);

        // Through the relation, not Meta::create(). Production writes bags that
        // way and the relation is what assigns the morph columns -- past fill(),
        // so they are absent from Meta::$fillable. Naming them here would have
        // stopped working, and rightly so: this test is about the relation, so it
        // should exercise the relation.
        $account->meta()->create(['meta' => $meta]);

        return $account;
    }

    public function test_a_transaction_carries_and_reads_its_own_bag(): void
    {
        $account = Account::create([
            'name' => 'Payer',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'HKD',
        ]);

        $transaction = Transaction::create([
            'account_id' => $account->id,
            'category_id' => null,
            'amount' => 10,
            'type' => 'withdraw',
            'description' => 'Foreign lunch',
            'ccy' => 'USD',
            'date' => '2026-01-01',
            'status' => 'posted',
        ]);

        $transaction->meta()->create(['meta' => ['card_amount' => '780.0000']]);

        $fresh = $transaction->fresh();

        $this->assertSame('780.0000', $fresh->meta->meta['card_amount']);
        // The appended accessor, which is what a page or a seeder would read.
        $this->assertSame('780.0000', $fresh->meta_data['card_amount']);
        $this->assertTrue($fresh->meta->metable->is($transaction));
    }

    public function test_a_transaction_and_an_account_do_not_see_each_others_bags(): void
    {
        // Same table, same id space, different model_type. If the morph ever
        // stopped discriminating, a transaction would read an account's card terms
        // and a brokerage would settle into a transaction.
        $account = Account::create([
            'name' => 'Shared Ids',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
        ]);
        $account->meta()->create(['meta' => ['term_days' => 20, 'statement_day' => 5]]);

        $transaction = Transaction::create([
            'account_id' => $account->id,
            'category_id' => null,
            'amount' => 10,
            'type' => 'charge',
            'description' => 'Lunch',
            'ccy' => 'HKD',
            'date' => '2026-01-01',
            'status' => 'posted',
        ]);

        // Force the collision: the transaction's id is made to equal the
        // account's, so a morph that ignored model_type would match the row.
        $transaction->meta()->create(['meta' => ['card_amount' => '780.0000']]);
        DB::table('meta')->where('model_id', $transaction->id)
            ->where('model_type', Transaction::class)->update(['model_id' => $account->id]);

        $this->assertNull($transaction->fresh()->meta);
        $this->assertSame(20, $account->fresh()->meta->meta['term_days']);
    }

    public function test_metable_resolves_back_to_the_owning_account(): void
    {
        $account = $this->accountWithMeta();

        $owner = Meta::firstOrFail()->metable;

        $this->assertInstanceOf(Account::class, $owner);
        $this->assertTrue($owner->is($account));
        $this->assertSame('Alpha', $owner->name);
    }

    public function test_several_accounts_each_resolve_their_own_metadata(): void
    {
        $alpha = $this->accountWithMeta(['term_days' => 15]);

        $beta = Account::create(['name' => 'Beta', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $beta->meta()->create(['meta' => ['term_days' => 20]]);

        $owners = Meta::with('metable')->get()->map(fn (Meta $m) => $m->metable->name);

        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $owners->all());
        $this->assertTrue(Meta::where('model_id', $alpha->id)->firstOrFail()->metable->is($alpha));
    }
}
