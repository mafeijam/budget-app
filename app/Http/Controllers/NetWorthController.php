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

        $first = Transaction::query()->min('date');

        // The three snapshots this page shows, asked for together. One at a time each
        // meant three full balance aggregates over every transaction, and the page already
        // knew it wanted all three.
        $days = [$at->toDateString(), $at->copy()->startOfMonth()->subDay()->toDateString()];

        if ($first !== null) {
            $days[] = Carbon::parse($first)->endOfMonth()->toDateString();
        }

        $days = array_values(array_unique($days));
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
            'lastMonth' => $against($at->copy()->startOfMonth()->subDay()->toDateString()),
            'since' => $first === null ? null : $against(Carbon::parse($first)->endOfMonth()->toDateString()),
            'history' => $worth->history($months, $today),
            'at' => $at->isSameDay($today) ? null : $at->toDateString(),
            'months' => $months,
            'periods' => NetWorth::PERIODS,
        ]);
    }
}
