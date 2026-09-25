<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meta extends Model
{
    use HasFactory;

    protected $table = 'meta';

    protected $casts = [
        'meta' => AsArrayObject::class,
    ];

    public function metable()
    {
        $this->morphTo();
    }
}
