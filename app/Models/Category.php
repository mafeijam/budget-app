<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    /**
     * The one attribute a client may set.
     *
     * A category is a name and nothing else, which is the whole reason the
     * allowlist is this short and not a copy of the table. See Account::$fillable
     * for why `id` and the timestamps are excluded, and MassAssignmentTest for what
     * keeps the two in step.
     *
     * @var array<int, string>
     */
    protected $fillable = ['name'];
}
