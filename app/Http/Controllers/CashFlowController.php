<?php

namespace App\Http\Controllers;

use App\Support\CashFlow;

class CashFlowController extends Controller
{
    public function index()
    {
        // today() is Hong Kong's, so the current month turns over when the app's day does.
        $report = CashFlow::lastMonths(today());

        return inertia('cash-flow', [
            'report' => $report,
            'months' => CashFlow::MONTHS,
        ]);
    }
}
