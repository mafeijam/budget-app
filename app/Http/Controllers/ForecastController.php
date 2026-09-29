<?php

namespace App\Http\Controllers;

use App\Support\Forecast;
use App\Support\Fx;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    public function index(Request $r)
    {
        // One of the offered horizons, or three months: anything else is a hand-edited URL.
        $months = in_array((int) $r->input('months'), Forecast::HORIZONS, true) ? (int) $r->input('months') : 3;

        // today() is Hong Kong's, so the forecast starts on the app's day, not the browser's.
        $forecast = Forecast::for(today(), $months);

        // A currency the cash accounts hold, shown in its own money; otherwise everything in
        // the base currency.
        $ccy = in_array($r->input('ccy'), $forecast->currencies(), true) ? $r->input('ccy') : null;

        return inertia('forecast', [
            'projection' => $forecast->projection($ccy),
            'ccy' => $ccy,
            'currencies' => $forecast->currencies(),
            'base' => Fx::BASE->value,
            'upcoming' => $forecast->upcoming($ccy),
            'outlook' => $forecast->monthOutlook($ccy),
            'warnings' => $forecast->warnings($ccy),
            'months' => $months,
            'horizons' => Forecast::HORIZONS,
            'upcomingDays' => Forecast::UPCOMING_DAYS,
        ]);
    }
}
