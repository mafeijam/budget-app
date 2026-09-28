<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Price extends Model
{
    /**
     * The table's columns less `id` and the timestamps, as on every model here -- see
     * Account::$fillable, and MassAssignmentTest for what keeps the two in step.
     *
     * @var array<int, string>
     */
    protected $fillable = ['symbol', 'date', 'close', 'ccy', 'source'];

    /**
     * The latest price on or before a day for each symbol, keyed by symbol.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, self>
     */
    public static function latestFor(array $symbols, string $onOrBefore): array
    {
        if ($symbols === []) {
            return [];
        }

        return self::query()
            ->whereIn('symbol', $symbols)
            ->where('date', '<=', $onOrBefore)
            ->orderBy('date')
            ->get()
            // Ascending, so keyBy leaves each symbol's latest day standing.
            ->keyBy('symbol')
            ->all();
    }
}
