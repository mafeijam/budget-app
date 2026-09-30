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
        // By due date unless asked otherwise: anything but 'charged' is a hand-edited URL.
        $card = $r->input('card') === 'charged' ? 'charged' : 'due';
        $both = CashFlow::both(today(), onDueDate: $card === 'due');
        $currencies = $both['currencies'];

        // A currency with rows, shown in its own money; otherwise everything in the base
        // currency.
        $ccy = in_array($r->input('ccy'), $currencies, true) ? $r->input('ccy') : null;

        return inertia('cash-flow', [
            'report' => $ccy === null
                ? array_filter([$both['combined']['report']])
                : array_values(array_filter($both['by_currency'], fn (array $section) => $section['ccy'] === $ccy)),
            'ccy' => $ccy,
            'card' => $card,
            'currencies' => $currencies,
            'base' => Fx::BASE->value,
            'unconverted' => $both['combined']['unconverted'],
            'months' => CashFlow::MONTHS,
        ]);
    }
}
