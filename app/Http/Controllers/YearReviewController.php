<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ForgetsTheHomeCache;
use App\Models\Price;
use App\Support\Fx;
use App\Support\YearReview;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class YearReviewController extends Controller
{
    public function index()
    {
        // Every year since the first is two seconds of reading, and the past ones hardly ever
        // change, so the whole review is kept -- keyed on what can change it, never on a clock.
        // A write through the app moves the home page's mark; the transactions' own count,
        // newest edit and highest id catch one that does not come through the app, as the
        // recurring rules recorded at 00:05 do not; the day moves the year running on; and the
        // last price fetch, the cron's at 06:30, moves values and rates.
        $rows = DB::table('transactions')->selectRaw('COUNT(*) AS n, MAX(updated_at) AS edited, MAX(id) AS last')->first();

        $key = implode('.', [
            'review',
            ForgetsTheHomeCache::mark(),
            $rows->n, $rows->edited ?? 'none', $rows->last ?? 0,
            today()->toDateString(),
            Price::where('source', 'yahoo')->max('updated_at') ?? 'none',
        ]);

        $review = Cache::remember($key, now()->addDay(), fn () => (new YearReview(today()))->all());

        return inertia('review', [
            // Every year, keyed by it: the year shown is the browser's to remember, as the
            // other pages' pickers are, so picking one is no visit. An object, so Inertia hands
            // it to json_encode as it is -- see ForecastController.
            'years' => (object) $review['years'],
            'unconverted' => $review['unconverted'],
            'base' => Fx::BASE->value,
            'thisYear' => today()->year,
        ]);
    }
}
