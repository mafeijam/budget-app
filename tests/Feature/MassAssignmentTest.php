<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Meta;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Which attributes a client is allowed to set, and that nothing outside that set
 * gets through.
 *
 * AppServiceProvider used to call Model::unguard(), which made every attribute of
 * every model mass assignable, everywhere, for the life of the request. That is
 * not a missing check but the removal of the check, so the two things worth
 * testing are the allowlist itself and the fact that it is enforced at the model
 * rather than at each call site that remembers to filter.
 */
class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The models that own a table, and so have attributes a client could name.
     *
     * The second element of each entry lists the columns no client may set, beyond
     * the `id` and timestamps every model shares. Only Meta has any: its morph
     * columns are assigned by the relation rather than by a client, which is the
     * whole reason they are absent from its allowlist. Carried here so the rule
     * below reads as "every column nobody else owns" rather than pretending all
     * four tables are shaped alike.
     *
     * User is absent because nothing here writes to it; it is asserted separately
     * below as the precedent this change follows rather than something to police.
     */
    private const MODELS = [
        Account::class => ['table' => 'accounts', 'not_client_set' => []],
        Category::class => ['table' => 'categories', 'not_client_set' => []],
        Meta::class => ['table' => 'meta', 'not_client_set' => ['model_id', 'model_type']],
        Transaction::class => ['table' => 'transactions', 'not_client_set' => []],
    ];

    /** Every model shares these: the key is the database's, the timestamps Eloquent's. */
    private const NOT_CLIENT_SET = ['id', 'created_at', 'updated_at'];

    public static function modelProvider(): array
    {
        $cases = [];

        foreach (self::MODELS as $model => $shape) {
            $cases[$model] = [$model, $shape['table'], $shape['not_client_set']];
        }

        return $cases;
    }

    public function test_mass_assignment_is_not_globally_unguarded(): void
    {
        // Model::unguard() is a static switch, so removing it is the only way to
        // have it off anywhere at all. Asserted rather than assumed: it is one
        // line in a service provider, and a silent re-add would reopen every hole
        // in this file at once without failing any of them.
        $this->assertFalse(
            Model::isUnguarded(),
            'Model::unguard() is in effect; every attribute of every model is mass assignable again.'
        );
    }

    #[DataProvider('modelProvider')]
    public function test_a_fillable_allowlist_covers_exactly_the_client_settable_columns(
        string $model,
        string $table,
        array $notClientSet
    ): void {
        // The guard that makes the duplication safe. $fillable repeats the column
        // list in a second place, and a column added to a migration and forgotten
        // there would not fail loudly -- it would simply stop being written, and
        // the row would persist complete-looking and incomplete. That is the
        // silent-failure mode this file exists to prevent, so it is asserted
        // against the schema rather than left to review.
        $expected = array_values(array_diff(
            $this->columns($table),
            [...self::NOT_CLIENT_SET, ...$notClientSet]
        ));
        sort($expected);

        // Both sides sorted: an allowlist is written in the order a human reads it
        // in, which is not alphabetical, and comparing them as written would fail on
        // ordering rather than on membership.
        $actual = (new $model)->getFillable();
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            "{$model} (\$fillable) no longer matches the {$table} columns a client may set."
        );
    }

    #[DataProvider('modelProvider')]
    public function test_the_allowlisted_columns_are_actually_writable(
        string $model,
        string $table,
        array $notClientSet
    ): void {
        // The other direction, because an allowlist that is too narrow is its own
        // silent failure: every write strips the attribute and the row persists
        // without it. Comparing two lists cannot catch that, so each model is
        // filled with the columns it claims and asked which came out the far side.
        $columns = array_values(array_diff(
            $this->columns($table),
            [...self::NOT_CLIENT_SET, ...$notClientSet]
        ));
        sort($columns);

        // A bag of strings, which every column here will accept. id is among the
        // excluded because it is a bigint and the assertion is about what was
        // assigned, not about what the database would do with it.
        $filled = (new $model)->fill(array_combine($columns, array_fill_keys($columns, 'x')));

        $assigned = array_keys($filled->getAttributes());
        sort($assigned);

        $this->assertSame(
            $columns,
            $assigned,
            "Filling {$model} with its own \$fillable lost or gained an attribute."
        );
    }

    #[DataProvider('modelProvider')]
    public function test_a_non_allowlisted_column_is_refused(
        string $model,
        string $table,
        array $notClientSet
    ): void {
        // And the third face: whatever the allowlist leaves out must actually be
        // dropped, or the two tests above would agree with each other while both
        // being satisfied by an empty list.
        $refused = ['x' => 'y', ...array_fill_keys($notClientSet, 1), 'id' => 1, 'created_at' => 'now'];

        $this->assertSame(
            [],
            (new $model)->fill($refused)->getAttributes(),
            "{$model} accepted an attribute that is not on its allowlist."
        );
    }

    public function test_a_client_cannot_choose_a_primary_key(): void
    {
        // The failure this prevents is quiet rather than loud. A row can be
        // renumbered onto an id another row already holds, or moved to a high id
        // and left to collide with whatever is inserted next -- and neither shows
        // up as anything but a surprising id later.
        $filled = (new Account)->fill(['id' => 999, 'name' => 'Renumber']);

        $this->assertArrayNotHasKey('id', $filled->getAttributes());
        $this->assertSame('Renumber', $filled->name);
    }

    public function test_a_client_cannot_choose_a_creation_timestamp(): void
    {
        // Backdating created_at is how a row gets filed into the wrong period in
        // every list sorted by it, and it is not a field the form offers.
        $backdated = now()->subYears(5);

        $filled = (new Account)->fill(['created_at' => $backdated, 'name' => 'Stamped']);

        $this->assertArrayNotHasKey('created_at', $filled->getAttributes());
        $this->assertNull($filled->created_at);
    }

    public function test_a_client_cannot_choose_a_morph_owner(): void
    {
        // Meta rows are only ever written through the relation, which sets the
        // morph columns itself past fill() -- MorphOneOrMany::setForeignAttributesForCreate
        // assigns them with setAttribute. So the columns are absent from $fillable
        // on purpose, and this is what pins that: naming a model_id achieves
        // nothing, so a client cannot attach a bag to somebody else's row.
        $filled = (new Meta)->fill([
            'model_id' => 1,
            'model_type' => Account::class,
            'meta' => ['term_days' => 15],
        ]);

        $this->assertArrayNotHasKey('model_id', $filled->getAttributes());
        $this->assertArrayNotHasKey('model_type', $filled->getAttributes());
        // getArrayCopy() rather than the property: the column is cast to an
        // ArrayObject, so the bag arrives as an object however it was assigned.
        $this->assertSame(['term_days' => 15], $filled->meta->getArrayCopy());
    }

    public function test_a_written_row_still_gets_its_timestamps_from_the_database_side(): void
    {
        // The other half of excluding the timestamps from $fillable: they are not
        // writable, but they are not skipped either. Eloquent assigns them on save
        // through setAttribute, which bypasses fill(), so a row created without
        // them in the payload still has both.
        $before = now()->subSecond();

        $account = Account::create(['name' => 'Stamped', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $this->assertNotNull($account->created_at);
        $this->assertNotNull($account->updated_at);
        $this->assertTrue($account->created_at->greaterThanOrEqualTo($before));
    }

    public function test_the_user_model_keeps_its_own_allowlist(): void
    {
        // Not part of MODELS, because nothing here writes to it -- but it is the
        // one model that already had a $fillable, so it is the precedent this
        // change follows. Asserted so the two cannot drift apart unnoticed.
        $this->assertSame(['name', 'email', 'password'], (new User)->getFillable());
    }

    private function columns(string $table): array
    {
        $rows = DB::select(
            'SELECT column_name AS col FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        $columns = array_map(fn ($row) => $row->col, $rows);
        sort($columns);

        return $columns;
    }
}
