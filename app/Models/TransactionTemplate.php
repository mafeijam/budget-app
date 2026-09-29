<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved set of the transaction form's values, merged whole over the form. Nothing sums
 * one. The account and category are columns so a cascade can reach them.
 */
class TransactionTemplate extends Model
{
    protected $fillable = ['name', 'account_id', 'category_id', 'payload'];

    /** An array rather than Meta's ArrayObject: it is spread over the form, not read by key. */
    protected $casts = ['payload' => 'array'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
