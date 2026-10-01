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

        // The day the cards show: a snapshot picked on the chart, or today. A malformed or
        // future day is today, since there is nothing to show past it.
        $at = $r->input('at');
        $at = is_string($at) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) && checkdate(
            (int) substr($at, 5, 2), (int) substr($at, 8, 2), (int) substr($at, 0, 4)
        ) && $at < $today->toDateString() ? Carbon::parse($at) : $today;

        // The comparison's start, where the chart starts: the first whole year (Ledger).
        $first = Ledger::start();

        // The cards' three snapshots and the chart's, asked for together. One at a time each
        // meant a full balance aggregate over every transaction and a read of the closes, and
        // the page already knew every day it wanted.
        $days = [$at->toDateString(), $at->copy()->startOfMonth()->subDay()->toDateString()];

        if ($first !== null) {
            $days[] = Carbon::parse($first)->endOfMonth()->toDateString();
        }

        //
        // Not with a day inside a month that is not the last asked for: the balances are read
        // by month, so every day but the last must be a month end, and the 15th asked
        // alongside today would get the whole month's movements.
        $chart = $worth->historyDays($months, $today);
        $together = $at->isSameDay($today) || $at->isLastOfMonth();
        $days = array_values(array_unique($together ? [...$days, ...$chart] : $days));
        $shown = array_combine($days, $worth->onMany($days));

        $current = $shown[$at->toDateString()];

        // The comparisons the net worth card makes: last month's end, and the first month's.
        $against = fn (string $day) => [
            'date' => $day,
            'net_worth' => $shown[$day]['net_worth'],
            'change' => (string) BigDecimal::of($current['net_worth'])->minus($shown[$day]['net_worth']),
        ];

        return inertia('net-worth', [
            'base' => Fx::BASE->value,
            'current' => $current,
            // What each currency went at on the day the cards show, so a row held in another
            // can say what converted it. That day, not today: a snapshot picked on the chart
            // was built at that day's rate and a rate from today would not be the one.
            'rates' => $worth->ratesOn($at->toDateString()),
            'lastMonth' => $against($at->copy()->startOfMonth()->subDay()->toDateString()),
            'since' => $first === null ? null : $against(Carbon::parse($first)->endOfMonth()->toDateString()),
            'history' => $together
                ? NetWorth::chartPoints(array_map(fn (string $day) => $shown[$day], $chart))
                : $worth->history($months, $today),
            'at' => $at->isSameDay($today) ? null : $at->toDateString(),
            'months' => $months,
            'periods' => NetWorth::PERIODS,
        ]);
    }
}
