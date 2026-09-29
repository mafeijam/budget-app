<?php

namespace App\Http\Controllers;

use App\Support\Forecast;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    public function index(Request $r)
    {
        // One of the offered horizons, or three months: anything else is a hand-edited URL.
        $months = in_array((int) $r->input('months'), Forecast::HORIZONS, true) ? (int) $r->input('months') : 3;

        // today() is Hong Kong's, so the forecast starts on the app's day, not the browser's.
        $forecast = Forecast::for(today(), $months);

        return inertia('forecast', [
            'projection' => $forecast->projection(),
            'upcoming' => $forecast->upcoming(),
            'outlook' => $forecast->monthOutlook(),
            'warnings' => $forecast->warnings(),
            'months' => $months,
            'horizons' => Forecast::HORIZONS,
            'upcomingDays' => Forecast::UPCOMING_DAYS,
        ]);
    }
}
