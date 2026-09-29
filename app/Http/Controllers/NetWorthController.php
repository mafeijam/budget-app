<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Support\Fx;
use App\Support\NetWorth;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Http\Request;

class NetWorthController extends Controller
{
    public function index(Request $r)
    {
        // One of the offered spacings, or monthly: anything else is a hand-edited URL.
        $months = in_array((int) $r->input('months'), NetWorth::PERIODS, true) ? (int) $r->input('months') : 1;

        $growth = in_array((int) $r->input('growth'), NetWorth::GROWTHS, true) ? (int) $r->input('growth') : 0;

        $worth = new NetWorth;
        $today = today();

        // The day the cards show: a snapshot picked on the chart, or today. A malformed or
        // future day is today, since there is nothing to show past it.
        $at = $r->input('at');
        $at = is_string($at) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) && checkdate(
            (int) substr($at, 5, 2), (int) substr($at, 8, 2), (int) substr($at, 0, 4)
        ) && $at < $today->toDateString() ? Carbon::parse($at) : $today;

        $first = Transaction::query()->min('date');
        $current = $worth->on($at->toDateString());

        // The comparisons the net worth card makes: last month's end, and the first month's.
        $against = fn (string $day) => [
            'date' => $day,
            'net_worth' => $then = $worth->on($day)['net_worth'],
            'change' => (string) BigDecimal::of($current['net_worth'])->minus($then),
        ];

        return inertia('net-worth', [
            'base' => Fx::BASE->value,
            'current' => $current,
            'lastMonth' => $against($at->copy()->startOfMonth()->subDay()->toDateString()),
            'since' => $first === null ? null : $against(Carbon::parse($first)->endOfMonth()->toDateString()),
            'history' => $worth->history($months, $today),
            // Off unless asked for, and at a return the page offers: a projection is a
            // what-if, and the chart without one is the record.
            'projection' => $r->boolean('project') ? $worth->projection($months, $today, $growth) : [],
            'growth' => $growth,
            'growths' => NetWorth::GROWTHS,
            'projectionMonths' => NetWorth::PROJECTION_MONTHS,
            'at' => $at->isSameDay($today) ? null : $at->toDateString(),
            'months' => $months,
            'periods' => NetWorth::PERIODS,
        ]);
    }
}
