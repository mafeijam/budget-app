<?php

namespace App\Http\Controllers;

use App\Support\CashFlow;
use App\Support\Fx;
use Illuminate\Http\Request;

class CashFlowController extends Controller
{
    public function index(Request $r)
    {
        // today() is Hong Kong's, so the current month turns over when the app's day does.
        $byCurrency = CashFlow::lastMonths(today());
        $currencies = array_column($byCurrency, 'ccy');

        // A currency with rows, shown in its own money; otherwise everything in the base
        // currency.
        $ccy = in_array($r->input('ccy'), $currencies, true) ? $r->input('ccy') : null;
        $combined = $ccy === null ? CashFlow::combined(today()) : null;

        return inertia('cash-flow', [
            'report' => $ccy === null
                ? array_filter([$combined['report']])
                : array_values(array_filter($byCurrency, fn (array $section) => $section['ccy'] === $ccy)),
            'ccy' => $ccy,
            'currencies' => $currencies,
            'base' => Fx::BASE->value,
            'unconverted' => $combined['unconverted'] ?? [],
            'months' => CashFlow::MONTHS,
        ]);
    }
}
