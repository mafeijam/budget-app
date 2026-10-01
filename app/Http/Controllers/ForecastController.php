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

        // Every view the currency dropdown can ask for, in one response: the dropdown is the
        // browser's to remember, like the other pages', so the server cannot be told which it
        // is and a choice must not cost a visit. `all` is every account in the base currency.
        $views = ['all' => null];

        foreach ($forecast->currencies() as $code) {
            $views[$code] = $code;
        }

        return inertia('forecast', [
            'views' => array_map(fn (?string $only) => [
                'projection' => $forecast->projection($only),
                'upcoming' => $forecast->upcoming($only),
                'outlook' => $forecast->monthOutlook($only),
                'warnings' => $forecast->warnings($only),
            ], $views),
            'currencies' => $forecast->currencies(),
            'base' => Fx::BASE->value,
            'months' => $months,
            'horizons' => Forecast::HORIZONS,
            'upcomingDays' => Forecast::UPCOMING_DAYS,
        ]);
    }
}
