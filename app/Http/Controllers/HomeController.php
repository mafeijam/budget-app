<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\AccountBalance;
use App\Support\Attention;
use App\Support\CardStatement;
use App\Support\Forecast;
use App\Support\Fx;
use App\Support\NetWorth;
use App\Support\Positions;
use Brick\Math\BigDecimal;
use Inertia\Inertia;

class HomeController extends Controller
{
    /** How far ahead Coming up looks, in days, and how many rows it lists. */
    private const UPCOMING_DAYS = 14;

    private const UPCOMING_SHOWN = 6;

    /** How many months back the headline's lines reach. */
    private const TREND_MONTHS = 6;

    public function index()
    {
        // A closed account still holding money stays, or the total could not be
        // accounted for.
        $cashAccounts = Account::query()
            ->where('type', AccountType::Cash->value)
            ->orderBy('name')
            ->get();

        $balances = AccountBalance::forAccounts($cashAccounts);

        $cash = $cashAccounts
            ->filter(fn (Account $account) => $account->status === 'active'
                || ! BigDecimal::of($balances[$account->id])->isZero())
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'ccy' => $account->ccy,
                'status' => $account->status,
                'balance' => $balances[$account->id],
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
            ->map(fn (Account $broker) => [
                'id' => $broker->id,
                'name' => $broker->name,
                'ccy' => $broker->ccy,
                'status' => $broker->status,
                ...Positions::valued($broker)['totals'],
            ])
            ->filter(fn (array $broker) => $broker['status'] === 'active' || $broker['open'] > 0)
            ->values();

        $today = today();
        $day = $today->toDateString();

        // The net worth page's figures for today, and its change since last month's end.
        // Asked for together, since the page wants both and each was a full aggregate.
        $worth = new NetWorth;
        $lastMonthEnd = $today->copy()->startOfMonth()->subDay()->toDateString();

        [$now, $then] = $worth->onMany([$day, $lastMonthEnd]);

        $forecast = Forecast::for($today, 3);
        $until = $today->copy()->addDays(self::UPCOMING_DAYS)->toDateString();
        $upcoming = collect($forecast->upcoming())->filter(fn (array $event) => $event['date'] <= $until)->values();

        // Owed in the base currency at today's rate, beside the section heading.
        $fx = Fx::for($statements->pluck('card.ccy')->all());
        $owed = $statements->reduce(
            fn (BigDecimal $total, array $statement) => $total->plus($fx->toBase($statement['owed'], $statement['card']['ccy'], $day) ?? BigDecimal::zero()),
            BigDecimal::zero()
        );

        return inertia('index', [
            'cash' => $cash,
            'brokerages' => $brokerages,
            'statements' => $statements,
            'base' => Fx::BASE->value,
            'headline' => [
                ...collect($now)->only(['net_worth', 'cash', 'cards', 'value', 'unrealised', 'unpriced', 'unconverted'])->all(),
                'last_month' => $then['net_worth'],
                'change' => (string) BigDecimal::of($now['net_worth'])->minus($then['net_worth']),
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
            'trend' => Inertia::defer(
                fn () => $worth->history(1, today(), self::TREND_MONTHS),
                rescue: true
            ),
            'attention' => Attention::items($today, $cash, $statements, $forecast, $brokerages->sum('open') > 0),
            'month' => $forecast->monthOutlook()[0] ?? null,
            'upcoming' => $upcoming->take(self::UPCOMING_SHOWN)->all(),
            'upcomingMore' => max(0, $upcoming->count() - self::UPCOMING_SHOWN),
            'upcomingDays' => self::UPCOMING_DAYS,
        ]);
    }
}
