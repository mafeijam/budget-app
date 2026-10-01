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
     * seriesFor()'s table cut to the closes some day in $days could be answered by: per
     * symbol, the last close on or before each day and after the one before it. A caller that
     * walks the series for the last close on or before a day finds the same one, because a
     * close another kept in its stretch would be later and still not past the day.
     *
     * For a few scattered days, where the window seriesFor() reads is years of closes for a
     * handful of answers: the net worth page's day, last month's end and the first month's
     * read fifty thousand rows for a hundred, and building that table was most of the page.
     *
     * @param  array<int, string>  $symbols
     * @param  list<string>  $days
     * @return array<string, array<string, array{close: string, ccy: string}>>
     */
    public static function seriesOn(array $symbols, array $days): array
    {
        if ($symbols === [] || $days === []) {
            return [];
        }

        $days = array_values(array_unique($days));
        sort($days);

        // The first day's stretch is everything before it, and latestFor() answers that
        // without reading it.
        $series = [];

        foreach (self::latestFor($symbols, $days[0]) as $price) {
            $series[$price->symbol][$price->date] = ['close' => (string) $price->close, 'ccy' => $price->ccy];
        }

        $later = array_slice($days, 1);

        if ($later === []) {
            return $series;
        }

        // Which later stretch a close falls in: the first asked day on or after it, found by
        // halving rather than a CASE down the list, which tried every day on every close --
        // a monthly chart is 125 days against fifty thousand closes, and twice the query.
        $bindings = [];
        $stretch = self::stretchOf($later, 0, count($later) - 1, $bindings);

        foreach (
            DB::table('prices')
                ->joinSub(
                    self::closes($symbols)
                        ->select('symbol', DB::raw('max(date) as date'))
                        ->where('date', '>', $days[0])
                        ->where('date', '<=', end($days))
                        ->groupBy('symbol')
                        // One day is one stretch, and a bare 0 there is GROUP BY's first column.
                        ->when(count($later) > 1, fn (Builder $q) => $q->groupByRaw($stretch, $bindings)),
                    'latest',
                    fn (JoinClause $join) => $join
                        ->on('prices.symbol', '=', 'latest.symbol')
                        ->on('prices.date', '=', 'latest.date')
                )
                ->orderBy('prices.date')
                ->get(['prices.symbol', 'prices.date', 'prices.close', 'prices.ccy']) as $price
        ) {
            $series[$price->symbol][$price->date] = ['close' => (string) $price->close, 'ccy' => $price->ccy];
        }

        // Ascending by date, as the caller walks it: the first stretch came first, then the
        // rest in date order, and ksort says so rather than leaving it to that.
        foreach ($series as &$closes) {
            ksort($closes);
        }

        return $series;
    }

    /**
     * The index of the first of $days[$lo..$hi] on or after a close's date, as nested IFs.
     * The days are bound, in the order their placeholders appear; the indexes are this
     * method's own integers.
     *
     * @param  list<string>  $days  ascending
     * @param  list<string>  $bindings
     */
    private static function stretchOf(array $days, int $lo, int $hi, array &$bindings): string
    {
        if ($lo === $hi) {
            return (string) $lo;
        }

        $mid = intdiv($lo + $hi, 2);
        $bindings[] = $days[$mid];

        return 'IF(date <= ?, '.self::stretchOf($days, $lo, $mid, $bindings).', '.self::stretchOf($days, $mid + 1, $hi, $bindings).')';
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

        // A lookup per symbol, the newest close on or before the day off the (symbol, date)
        // index, and nothing else read. MAX(date) grouped by symbol looked like the same
        // thing and was not: MySQL scans every close up to the day to find each maximum,
        // which on a recent day is all fifty thousand, 75 ms against 15.
        $dives = array_map(
            fn (string $symbol) => DB::table('prices')
                ->where('symbol', $symbol)
                ->where('date', '<=', $onOrBefore)
                ->orderByDesc('date')
                ->limit(1),
            array_values(array_unique($symbols))
        );

        $union = array_shift($dives);

        foreach ($dives as $dive) {
            $union->unionAll($dive);
        }

        return self::query()
            ->fromSub($union, 'prices')
            ->orderBy('symbol')
            ->get()
            ->keyBy('symbol')
            ->all();
    }

    /** The closes of a set of symbols, as rows rather than models. */
    protected static function closes(array $symbols): Builder
    {
        return DB::table('prices')->whereIn('symbol', $symbols);
    }
}
