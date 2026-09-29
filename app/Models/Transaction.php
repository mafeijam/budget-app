<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory, HasMeta;

    /**
     * The second gate, not the first: TransactionData derives a trade's amount and
     * defaults the status before anything lands here.
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

    /** Eager-load `account` wherever a list is built, or this is a query per row. */
    public function getAccountNameAttribute(): ?string
    {
        return $this->account?->name;
    }

    public function getAccountCcyAttribute(): ?string
    {
        return $this->account?->ccy;
    }

    public function getAccountTypeAttribute(): ?string
    {
        return $this->account?->type;
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
