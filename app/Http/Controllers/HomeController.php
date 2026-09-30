<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Middleware\ForgetsTheHomeCache;
use App\Models\Account;
use App\Support\AccountBalance;
use App\Support\Attention;
use App\Support\CardStatement;
use App\Support\Forecast;
use App\Support\Fx;
use App\Support\NetWorth;
use App\Support\Positions;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Support\Header;

class HomeController extends Controller
{
    /** How far ahead Coming up looks, in days, and how many rows it lists. */
    private const UPCOMING_DAYS = 14;

    private const UPCOMING_SHOWN = 6;

    /** How many months the forecast on this page looks ahead. */
    private const FORECAST_MONTHS = 3;

    /**
     * How long a cached prop is reused, in seconds.
     *
     * A month-end snapshot and a projection over the coming days do not change because the
     * user looked at the page again, so an hour is long enough to make a second visit cost
     * almost nothing and short enough that an idle tab is not a day out of date. The mark
     * on the key, not the clock, is what handles an edit; see ForgetsTheHomeCache.
     */
    private const ONCE_FOR = 3600;

    /** How many months back the headline's lines reach. */
    private const TREND_MONTHS = 6;

    /**
     * The figures a headline card carries a month-on-month change for, and the keys the
     * trend's points are indexed by. Spelled out rather than taken from a snapshot's keys
     * so a new key in a snapshot cannot quietly start driving the cards.
     */
    private const TREND_FIGURES = ['net_worth', 'cash', 'cards', 'value'];

    /**
     * Built on first use, and not at all when every prop that wants it is already cached.
     * On a revisit inside the hour this is the whole point: it is over half the page.
     */
    private ?Forecast $forecast = null;

    private ?Collection $upcoming = null;

    public function index(Request $request)
    {
        // A partial reload for the deferred line still comes through here, and Inertia
        // discards everything it does not want once the action has returned -- so the line
        // cost a whole page plus a line. Only the line is built for it.
        if (trim((string) $request->header(Header::PARTIAL_ONLY, '')) === 'trend') {
            return inertia('index', ['trend' => $this->trend()]);
        }

        // A closed account still holding money stays, or the total could not be
        // accounted for.
        $cashAccounts = Account::query()
            ->where('type', AccountType::Cash->value)
            ->orderBy('name')
            ->get();

        $balances = AccountBalance::forAccounts($cashAccounts);

        // Today's rate for every currency here, so a row held in another shows its base too.
        $rates = Fx::for(Account::query()->distinct()->pluck('ccy')->all());
        $inBase = fn (string $amount, string $ccy) => $ccy === Fx::BASE->value
            ? null
            : $rates->toBase($amount, $ccy, today()->toDateString())?->__toString();

        $cash = $cashAccounts
            ->filter(fn (Account $account) => $account->status === 'active'
                || ! BigDecimal::of($balances[$account->id])->isZero())
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'ccy' => $account->ccy,
                'status' => $account->status,
                'balance' => $balances[$account->id],
                'base' => $inBase($balances[$account->id], $account->ccy),
            ])
            ->values();

        // Inactive cards included: closing a card does not pay it.
        $cards = Account::query()
            ->where('type', AccountType::Card->value)
            ->with('meta')
            ->orderBy('name')
            ->get();

        $periods = CardStatement::forAccounts($cards);

        $statements = $cards->flatMap(fn (Account $card) => ($periods[$card->id] ?? collect())
            ->reject->isSettled()
            ->map(fn (CardStatement $statement) => [
                'card' => ['id' => $card->id, 'name' => $card->name, 'ccy' => $card->ccy],
                ...$statement->toArray(),
            ]))
            ->sortBy('due_date')
            ->values();

        // Beside the cash, not in it: shares at market value are not money.
        $brokerages = Account::query()
            ->where('type', AccountType::Security->value)
            ->orderBy('name')
            ->get()
            ->map(function (Account $broker) use ($inBase) {
                $totals = Positions::valued($broker)['totals'];

                return [
                    'id' => $broker->id,
                    'name' => $broker->name,
                    'ccy' => $broker->ccy,
                    'status' => $broker->status,
                    ...$totals,
                    'market_value_base' => $inBase($totals['market_value'], $broker->ccy),
                    'unrealised_base' => $inBase($totals['unrealised'], $broker->ccy),
                ];
            })
            ->filter(fn (array $broker) => $broker['status'] === 'active' || $broker['open'] > 0)
            ->values();

        $today = today();
        $day = $today->toDateString();

        // The net worth page's figures for today, and its change since last month's end.
        // Asked for together, since the page wants both and each was a full aggregate.
        $worth = new NetWorth;
        $lastMonthEnd = $today->copy()->startOfMonth()->subDay()->toDateString();

        [$now, $then] = $worth->onMany([$day, $lastMonthEnd]);

        // Owed in the base currency at today's rate, beside the section heading.
        $fx = Fx::for($statements->pluck('card.ccy')->all());
        $owed = $statements->reduce(
            fn (BigDecimal $total, array $statement) => $total->plus($fx->toBase($statement['owed'], $statement['card']['ccy'], $day) ?? BigDecimal::zero()),
            BigDecimal::zero()
        );

        // Carried on the key of every cached prop below. A write moves it, so the keys the
        // browser remembers stop matching and the props are built again.
        $mark = ForgetsTheHomeCache::mark();

        return inertia('index', [
            'cash' => $cash,
            'brokerages' => $brokerages,
            'statements' => $statements,
            'base' => Fx::BASE->value,
            'headline' => [
                ...collect($now)->only(['net_worth', 'cash', 'cards', 'value', 'unrealised', 'unpriced', 'unconverted'])->all(),

                // Both keyed by the same figure the client holds, so every card's note comes
                // from one expression and net worth stops being the special case. $then is
                // the whole snapshot for the last month's end, so the other three were here
                // already and only net_worth was being read out of it.
                'change' => collect(self::TREND_FIGURES)
                    ->mapWithKeys(fn (string $key) => [
                        $key => (string) BigDecimal::of($now[$key])->minus($then[$key]),
                    ])->all(),
                'last_month' => collect(self::TREND_FIGURES)
                    ->mapWithKeys(fn (string $key) => [$key => $then[$key]])->all(),
                'owed' => (string) $owed->toScale(4),
            ],
            // Month ends and today's, for each headline card's line.
            //
            // Deferred, and it is what is left worth deferring: six period snapshots for a
            // sparkline drawn under figures that are already on screen. Nothing waits on
            // it, so it should not hold the page up.
            //
            // rescued, because a trend that cannot be computed is a missing line and not a
            // broken page -- the headline figures are the page.
            //
            // Deferred and cached at once, though the cache only earns its keep on a
            // prefetched visit: the client fetches a deferred prop by naming it, and a once
            // prop named outright is always resolved. What makes the fetch cheap is the
            // short circuit at the top, not once().
            'trend' => Inertia::defer(fn () => $this->trend(), rescue: true)
                ->once()
                ->as("trend.{$mark}")
                ->until(self::ONCE_FOR),
            // The forecast and what it says. Together with the trend these are most of the
            // page, and on a visit where the browser already has all of them none of the
            // three closures runs, so the projection is never built at all.
            //
            // attention is here too, and it is the reason this works: it shares the forecast
            // object, so while it was built every visit the other two were not buying
            // anything. Its overdue and pending notices are worth a recomputation after an
            // edit, and the mark is what makes an edit force one.
            'attention' => Inertia::once(
                fn () => Attention::items($today, $cash, $statements, $this->forecast($today), $brokerages->sum('open') > 0)
            )->as("attention.{$mark}")->until(self::ONCE_FOR),
            'month' => Inertia::once(fn () => $this->forecast($today)->monthOutlook()[0] ?? null)
                ->as("month.{$mark}")->until(self::ONCE_FOR),
            'upcoming' => Inertia::once(fn () => $this->upcoming($today)->take(self::UPCOMING_SHOWN)->values()->all())
                ->as("upcoming.{$mark}")->until(self::ONCE_FOR),
            // Cached with upcoming, not computed from it: a prop left out of the cache would
            // rebuild the list to count it, which is the work being avoided.
            'upcomingMore' => Inertia::once(fn () => max(0, $this->upcoming($today)->count() - self::UPCOMING_SHOWN))
                ->as("upcomingMore.{$mark}")->until(self::ONCE_FOR),
            'upcomingDays' => self::UPCOMING_DAYS,
        ]);
    }

    /**
     * Coming up, within the window the section looks at.
     */
    private function upcoming(Carbon $today): Collection
    {
        if ($this->upcoming !== null) {
            return $this->upcoming;
        }

        $until = $today->copy()->addDays(self::UPCOMING_DAYS)->toDateString();

        return $this->upcoming = collect($this->forecast($today)->upcoming())
            ->filter(fn (array $event) => $event['date'] <= $until)
            ->values();
    }

    private function forecast(Carbon $today): Forecast
    {
        return $this->forecast ??= Forecast::for($today, self::FORECAST_MONTHS);
    }

    /**
     * A month end per figure, and today for the month still running, for the line under each
     * headline card.
     */
    private function trend(): array
    {
        return (new NetWorth)->history(1, today(), self::TREND_MONTHS);
    }
}
