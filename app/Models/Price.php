<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Price extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['symbol', 'date', 'close', 'ccy', 'source'];

    /**
     * Every close for a set of symbols up to a day, in one query, keyed symbol then date.
     *
     * A chart of net worth asks what each holding was worth on every period end, and
     * latestFor() answered one day at a time. This reads the same rows once and lets the
     * caller pick the last close on or before whatever day it is holding, which is the
     * only thing latestFor() was doing.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, array<string, array{close: string, ccy: string}>>
     */
    public static function seriesFor(array $symbols, string $onOrBefore): array
    {
        if ($symbols === []) {
            return [];
        }

        $series = [];

        foreach (
            self::query()->whereIn('symbol', $symbols)
                ->where('date', '<=', $onOrBefore)
                ->orderBy('date')
                ->get() as $price
        ) {
            $series[$price->symbol][$price->date] = ['close' => (string) $price->close, 'ccy' => $price->ccy];
        }

        return $series;
    }

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
