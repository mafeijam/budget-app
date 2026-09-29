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
     * Not the morph columns, which the relation sets past fill(); allowing them would let
     * a client attach a bag to somebody else's row.
     *
     * @var array<int, string>
     */
    protected $fillable = ['meta'];

    protected $casts = [
        'meta' => AsArrayObject::class,
    ];

    /** Columns named, or morphTo() derives metable_id / metable_type from the method name. */
    public function metable(): MorphTo
    {
        return $this->morphTo('metable', 'model_type', 'model_id');
    }
}
