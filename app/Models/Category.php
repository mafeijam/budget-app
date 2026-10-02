<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = ['name'];

    /**
     * Every category as a picker offers it: the most used first, so the usual one is near the
     * top, then by name, so a tie keeps a stable order. Counted over transactions only.
     *
     * @return Collection<int, self>
     */
    public static function byUse(): Collection
    {
        return self::query()
            ->leftJoin('transactions', 'transactions.category_id', '=', 'categories.id')
            ->select('categories.id', 'categories.name')
            // row_count, not usage: the second is a reserved word in MySQL.
            ->selectRaw('COUNT(transactions.id) AS row_count')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('row_count')
            ->orderBy('categories.name')
            ->get();
    }
}
