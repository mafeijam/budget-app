<?php

namespace App\Http\Controllers;

use App\Support\Fx;
use App\Support\Ledger;
use App\Support\NetWorth;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Http\Request;

class NetWorthController extends Controller
{
    public function index(Request $r)
    {
        // One of the offered spacings, or yearly: anything else is a hand-edited URL.
        $months = in_array((int) $r->input('months'), NetWorth::PERIODS, true) ? (int) $r->input('months') : 12;

        $worth = new NetWorth;
        $today = today();
        $todayString = $today->toDateString();

        // The window's two months, or the whole ledger up to today. A month the ledger cannot
        // show -- malformed, still to come, or before the first transaction -- is no window at
        // all, as a hand-edited URL is.
        $first = Ledger::start();
        $earliest = $first === null ? null : Carbon::parse($first)->startOfMonth()->toDateString();
        $from = self::month($r->input('from'), $earliest, $todayString);
        $to = self::month($r->input('to'), $earliest, $todayString);

        // An end before the start is not a shorter window but no window: the two are read as
        // one, so a hand-edited pair cannot ask for days that are not there.
        if ($from !== null && $to !== null && $to < $from) {
            $to = null;
        }

        $chart = $worth->historyDays($months, $today, from: $from, to: $to);
        $end = end($chart) ?: $todayString;

        // The month the cards read, and its own key. It is a month and not a day because the
        // balances are read by month: a day inside one silently took the whole month's
        // movements. And it is a key of its own rather than the window's end, because a
        // snapshot inside the window is a different question from where the window stops --
        // sharing the one made clicking a point on the chart truncate the chart.
        $at = self::month($r->input('at'), $from ?? $earliest, $to ?? $todayString);
        $day = $at === null ? $end : self::monthEnd($at, $today);

        // Where the window starts is where the "since" reads against: two answers to the same
        // question, and the one the chart already gives. A window starting this month has no
        // month end yet, so that is today and the comparison reads as no change.
        $since = $first === null
            ? null
            : Carbon::parse($from ?? $first)->endOfMonth()->min(Carbon::parse($day))->toDateString();

        // The cards' three snapshots and the chart's, asked for together. One at a time each
        // meant a full balance aggregate over every transaction and a read of the closes, and
        // the page already knew every day it wanted. Every one of them is a month end or
        // today, which is what makes asking them together sound.
        $days = [$day, Carbon::parse($day)->startOfMonth()->subDay()->toDateString()];

        if ($since !== null) {
            $days[] = $since;
        }

        $days = array_values(array_unique([...$days, ...$chart]));
        $shown = array_combine($days, $worth->onMany($days));

        $current = $shown[$day];

        // The comparisons the net worth card makes: last month's end, and the window's first.
        $against = fn (string $day) => [
            'date' => $day,
            'net_worth' => $shown[$day]['net_worth'],
            'change' => (string) BigDecimal::of($current['net_worth'])->minus($shown[$day]['net_worth']),
        ];

        return inertia('net-worth', [
            'base' => Fx::BASE->value,
            'current' => $current,
            // What each currency went at on the day the cards show, so a row held in another
            // can say what converted it. That day, not today: a month picked on the chart was
            // built at that month's close and a rate from today would not be the one.
            'rates' => $worth->ratesOn($day),
            'lastMonth' => $against(Carbon::parse($day)->startOfMonth()->subDay()->toDateString()),
            'since' => $since === null ? null : $against($since),
            'history' => NetWorth::chartPoints(array_map(fn (string $d) => $shown[$d], $chart)),
            // The month picked, and the day it resolved to, so the chart can mark it without
            // working out a month's last day in the browser.
            'at' => $at === null ? null : substr($at, 0, 7),
            'snapshot' => $day,
            'months' => $months,
            'periods' => NetWorth::PERIODS,
            // The window, its floor, and the server's own day: a month picker bounded by the
            // browser's today is a month the server has no prices for yet.
            'from' => $from === null ? null : substr($from, 0, 7),
            'to' => $to === null ? null : substr($to, 0, 7),
            'earliest' => $earliest === null ? null : substr($earliest, 0, 7),
            'today' => $todayString,
        ]);
    }

    /**
     * A well-formed month, YYYY-MM, as the first of it, and only one inside the bounds given.
     */
    private static function month(mixed $value, ?string $from, string $to): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1
            && $value >= substr($from ?? '', 0, 7)
            && $value <= substr($to, 0, 7)
                ? "{$value}-01"
                : null;
    }

    /**
     * The day a month's snapshot is read on: its last day, or today where the month is still
     * running and so has no last day to read yet.
     */
    private static function monthEnd(string $month, Carbon $today): string
    {
        $end = Carbon::parse($month)->endOfMonth()->toDateString();

        return $end < $today->toDateString() ? $end : $today->toDateString();
    }
}
