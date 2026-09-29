<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class Price extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['symbol', 'date', 'close', 'ccy', 'source'];

    /**
     * Every close a chart needs: those inside a window, and the last one before it for each
     * symbol, keyed symbol then date.
     *
     * A chart of net worth asks what each holding was worth on every period end, and
     * latestFor() answered one day at a time. This reads the window once and lets the caller
     * pick the last close on or before whatever day it is holding.
     *
     * The window matters more than the one query. Asking for two days meant reading every
     * close since the symbol was first held -- ten years of them, over fifty thousand rows,
     * for the last row of each symbol. The bound is what makes a chart of a few months cost
     * a few months.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, array<string, array{close: string, ccy: string}>>
     */
    public static function seriesFor(array $symbols, string $from, string $onOrBefore): array
    {
        if ($symbols === []) {
            return [];
        }

        // Read as arrays rather than models: the result is a lookup table of four columns,
        // and hydrating fifty thousand of them to throw all but the close away was most of
        // what this cost.
        $series = [];

        foreach (
            self::closes($symbols)->where('date', '>=', $from)->where('date', '<=', $onOrBefore)
                ->orderBy('date')->cursor() as $price
        ) {
            $series[$price->symbol][$price->date] = ['close' => (string) $price->close, 'ccy' => $price->ccy];
        }

        // The last close before the window, per symbol. A day inside it falls back to this
        // until the window has a close of its own, which is the normal case for a symbol
        // that has gone untraded for weeks -- and the whole series when a chart opens on a
        // quiet month.
        foreach (self::latestFor($symbols, Carbon::parse($from)->subDay()->toDateString()) as $price) {
            $series[$price->symbol] = [$price->date => ['close' => (string) $price->close, 'ccy' => $price->ccy]]
                + ($series[$price->symbol] ?? []);
        }

        // Ascending by date, because the caller walks the series and stops at the first day
        // after the one it is looking for. The union above already orders them, and ksort
        // says so rather than leaving it to two queries happening to agree.
        foreach ($series as &$closes) {
            ksort($closes);
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
            ->joinSub(
                self::closes($symbols)
                    // The latest day per symbol, which (symbol, date) indexed answers as a
                    // range scan. Ranking the rows instead reads every close a symbol ever
                    // had to decide which one is last, and that is the difference between
                    // 33 lookups and fifty thousand rows -- read four times on the home page.
                    ->select('symbol', DB::raw('max(date) as date'))
                    ->where('date', '<=', $onOrBefore)
                    ->groupBy('symbol'),
                'latest',
                fn (JoinClause $join) => $join
                    ->on('prices.symbol', '=', 'latest.symbol')
                    ->on('prices.date', '=', 'latest.date')
            )
            ->orderBy('symbol')
            ->get(['prices.*'])
            ->keyBy('symbol')
            ->all();
    }

    /** The closes of a set of symbols, as rows rather than models. */
    protected static function closes(array $symbols): Builder
    {
        return DB::table('prices')->whereIn('symbol', $symbols);
    }
}
