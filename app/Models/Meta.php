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
