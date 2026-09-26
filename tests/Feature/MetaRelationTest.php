<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Meta;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the Meta <-> Account polymorphic relation.
 *
 * Meta::metable() shipped without a `return` statement, so it always evaluated
 * to null. Nothing called it, so the bug was invisible -- but the relation was
 * simply unusable. These tests exist so it cannot silently regress again, and
 * because the relation is the only place the two halves of HasMeta are joined
 * up: HasMeta::meta() defines the morphOne forward direction, metable()
 * defines the morphTo inverse.
 */
class MetaRelationTest extends TestCase
{
    use RefreshDatabase;

    private function accountWithMeta(array $meta = ['due' => 15]): Account
    {
        $account = Account::create([
            'name' => 'Alpha',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'USD',
        ]);

        Meta::create([
            'model_id' => $account->id,
            'model_type' => Account::class,
            'meta' => $meta,
        ]);

        return $account;
    }

    public function test_metable_returns_a_relation_and_not_null(): void
    {
        $this->accountWithMeta();

        $meta = Meta::firstOrFail();

        // The direct regression: the missing `return` made this null, so the
        // method had the signature of a relation but none of the behaviour.
        $this->assertInstanceOf(MorphTo::class, $meta->metable());
    }

    public function test_metable_resolves_back_to_the_owning_account(): void
    {
        $account = $this->accountWithMeta();

        $owner = Meta::firstOrFail()->metable;

        $this->assertInstanceOf(Account::class, $owner);
        $this->assertTrue($owner->is($account));
        $this->assertSame('Alpha', $owner->name);
    }

    public function test_the_relation_is_traversable_in_both_directions(): void
    {
        $account = $this->accountWithMeta();

        $this->assertTrue($account->meta->metable->is($account));
        $this->assertTrue(Meta::firstOrFail()->metable->is($account));
    }

    public function test_metable_can_be_eager_loaded(): void
    {
        $account = $this->accountWithMeta();

        // Eager loading the inverse morph is where a relation returning null
        // fails differently from a relation returning nothing, so it is worth
        // exercising on its own.
        $loaded = Meta::with('metable')->firstOrFail();

        $this->assertTrue($loaded->relationLoaded('metable'));
        $this->assertTrue($loaded->metable->is($account));
    }

    public function test_several_accounts_each_resolve_their_own_metadata(): void
    {
        $alpha = $this->accountWithMeta(['due' => 15]);

        $beta = Account::create(['name' => 'Beta', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        Meta::create(['model_id' => $beta->id, 'model_type' => Account::class, 'meta' => ['due' => 20]]);

        $owners = Meta::with('metable')->get()->map(fn (Meta $m) => $m->metable->name);

        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], $owners->all());
        $this->assertTrue(Meta::where('model_id', $alpha->id)->firstOrFail()->metable->is($alpha));
    }

    public function test_metable_is_null_when_the_parent_row_is_missing(): void
    {
        // Orphans cannot be produced through the models -- the morphOne is not
        // configured to cascade -- so this documents the boundary case
        // directly rather than through normal app usage.
        Meta::create(['model_id' => 999999, 'model_type' => Account::class, 'meta' => ['due' => null]]);

        $this->assertNull(Meta::firstOrFail()->metable);
    }

    public function test_model_id_column_type_matches_the_parent_primary_keys(): void
    {
        // meta.model_id was originally declared unsignedInteger while the
        // accounts.id / categories.id it points at are unsignedBigInteger. That
        // mismatch is invisible until a parent id passes 4,294,967,295, at which
        // point Meta::create() fails on a perfectly valid parent row. The
        // migration 2026_09_26_000100_widen_meta_model_id_to_match_bigint_
        // primary_keys realigns it; this asserts nothing drifts back.
        // The columns are aliased explicitly: information_schema reports them
        // upper-case, and whether the driver hands them back upper- or
        // lower-case depends on PDO::ATTR_CASE, so the aliases pin it down.
        $rows = DB::select(
            "SELECT table_name AS col_table, column_name AS col_name, data_type AS col_type
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND ((table_name = 'meta' AND column_name = 'model_id')
                  OR (table_name IN ('accounts', 'categories') AND column_name = 'id'))"
        );

        $types = [];
        foreach ($rows as $row) {
            $types[$row->col_table.'.'.$row->col_name] = $row->col_type;
        }

        $this->assertCount(3, $types, 'Expected to find meta.model_id, accounts.id and categories.id, got: '.implode(', ', array_keys($types)));

        foreach ($types as $column => $type) {
            $this->assertSame(
                'bigint',
                $type,
                "{$column} is {$type}; meta.model_id must stay the same width as the "
                .'primary keys it references.'
            );
        }
    }
}
