<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory, HasMeta;

    /**
     * The attributes a client may set: the table's columns less `id` and the two
     * timestamps.
     *
     * `amount` and `status` are listed but not thereby the client's to decide.
     * TransactionData overwrites the amount for a trade and defaults the status,
     * so what lands here has already been through the DTO -- the allowlist is the
     * second gate, not the first.
     *
     * See Account::$fillable for why `id` and the timestamps are excluded, and
     * MassAssignmentTest for what keeps the two in step.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'account_id',
        'category_id',
        'date',
        'type',
        'description',
        'amount',
        'ccy',
        'status',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
