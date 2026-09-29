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

        $worth = new NetWorth;
        $today = today();

        $first = Transaction::query()->min('date');
        $current = $worth->on($today->toDateString());

        // The comparisons the net worth card makes: last month's end, and the first month's.
        $against = fn (string $day) => [
            'date' => $day,
            'net_worth' => $then = $worth->on($day)['net_worth'],
            'change' => (string) BigDecimal::of($current['net_worth'])->minus($then),
        ];

        return inertia('net-worth', [
            'base' => Fx::BASE->value,
            'current' => $current,
            'lastMonth' => $against($today->copy()->startOfMonth()->subDay()->toDateString()),
            'since' => $first === null ? null : $against(Carbon::parse($first)->endOfMonth()->toDateString()),
            'history' => $worth->history($months, $today),
            'months' => $months,
            'periods' => NetWorth::PERIODS,
        ]);
    }
}
