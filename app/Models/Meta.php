<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Meta extends Model
{
    use HasFactory;

    protected $table = 'meta';

    /**
     * Only the bag. The morph columns are deliberately not here.
     *
     * A meta row is always written through the relation -- $account->meta()->create() --
     * and the relation assigns model_id and model_type itself, past fill(), via
     * MorphOneOrMany::setForeignAttributesForCreate. Naming them in the allowlist
     * would be permission a client could use to attach a bag to a row that is not
     * its own; leaving them out costs nothing, because the relation sets them
     * either way.
     *
     * See Account::$fillable for the general rule and MassAssignmentTest for what
     * keeps the two in step.
     *
     * @var array<int, string>
     */
    protected $fillable = ['meta'];

    protected $casts = [
        'meta' => AsArrayObject::class,
    ];

    /**
     * The account or category this metadata row belongs to.
     *
     * The inverse of the HasMeta::meta() morphOne relation.
     *
     * The morph name, column names and relation name are all passed explicitly
     * rather than inferred. morphTo() would otherwise derive the columns from
     * this method's own name and look for metable_id / metable_type, which do
     * not exist -- HasMeta::meta() registers the pair under the morph name
     * 'model', so the columns are model_id / model_type. Spelling them out
     * keeps the two halves of HasMeta visibly paired, and the relation tests in
     * tests/Feature/MetaRelationTest.php fail if they are ever changed apart.
     */
    public function metable(): MorphTo
    {
        return $this->morphTo('metable', 'model_type', 'model_id');
    }
}
