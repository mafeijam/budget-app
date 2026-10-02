<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Price;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What everything held is worth on a day, in the base currency: the cash, plus the stocks at
 * their last close on or before it, less what is still owed on a loan.
 *
 * What the cards owe is reported alongside and is not in this. It is a debt against money
 * already counted rather than money held, and netting the two nets a liability against the
 * cash that is set aside to pay it -- the same bill subtracted twice. So it is a line of its
 * own on the chart and a row of its own on the cards page, where it can be read for what it
 * is.
 *
 * A loan is subtracted, and that is not the same mistake. The money borrowed is in the cash
 * and nothing else records the debt, so leaving it out counts a loan as money earned. A loan
 * repaid by card instalments is owed until each statement's due date: see Loans.
 *
 * Read from the rows rather than stored, so a corrected transaction corrects every past
 * snapshot too. A holding with no close yet counts at cost, and one in a currency with no
 * rate yet is left out; both are counted, so a total missing something says so.
 */
class NetWorth
{
    /** The point spacings offered, in months. */
    public const PERIODS = [1, 3, 6, 12];

    /** @var Collection<int, Account> */
    private Collection $accounts;

    /** @var array<int, list<array<string, mixed>>> brokerage id => its trades */
    private array $trades = [];

    private Fx $fx;

    private Loans $loans;

    public function __construct()
    {
        $this->accounts = Account::query()->with('meta')->orderBy('name')->get();
        $this->loans = new Loans;

        foreach ($this->accounts->where('type', AccountType::Security->value) as $broker) {
            $this->trades[$broker->id] = Positions::tradesOf($broker);
        }

        $this->fx = Fx::for($this->accounts->pluck('ccy')->all());
    }

    /**
     * Every account's figure on a day, each in its own currency and in the base one.
     *
     * @return array<string, mixed>
     */
    public function on(string $day): array
    {
        return $this->onMany([$day])[0];
    }

    /**
     * What each currency held went at on a day, in the base currency.
     *
     * For a row held in another to say what converted its figure, which is the only place a
     * rate is ever stated rather than applied. Asked for the day the page shows rather than
     * for today: a snapshot picked on the chart was built at that day's rate, so a rate from
     * today would not be the one its figures went at. A currency with no rate on that day is
     * left out -- a row whose base figure is null was converted at nothing.
     *
     * @return array<string, string> currency => one unit of it in the base currency
     */
    public function ratesOn(string $day): array
    {
        $rates = [];

        foreach ($this->accounts->pluck('ccy')->unique() as $ccy) {
            $rate = $ccy === Fx::BASE->value ? null : $this->fx->rate($ccy, $day);

            if ($rate !== null) {
                $rates[$ccy] = $rate;
            }
        }

        return $rates;
    }

    /**
     * The same figures for a run of days, in a fixed number of queries rather than one per
     * day.
     *
     * Two things made this worth batching. The balance aggregate reads every transaction,
     * and a monthly chart against a ten-year ledger is 123 period ends, so 123 aggregates
     * were most of a five-second page. And the closes for the holdings were fetched once
     * per period end, which is the same rows read over and over.
     *
     * Both are now read once -- balances grouped by month and accumulated up to each day,
     * closes keyed by symbol and date -- and everything after that is arithmetic in PHP.
     * The per-day shape is unchanged, because this is the one implementation: on() is
     * onMany() for a single day, so the two cannot drift.
     *
     * @param  list<string>  $days
     * @return list<array<string, mixed>> one snapshot per day, in the order asked
     */
    public function onMany(array $days): array
    {
        if ($days === []) {
            return [];
        }

        $zero = BigDecimal::zero();

        $accounts = $this->accounts->whereIn('type', [
            AccountType::Cash->value, AccountType::Card->value,
        ]);

        // The readers behind this walk days in order, so they are given them in order.
        // A caller asking for today, last month and the first month together is asking
        // out of order, and gets its answers back in the order it asked.
        $inOrder = array_values(array_unique($days));
        sort($inOrder);

        $balanceSeries = AccountBalance::seriesFor($accounts, $inOrder);

        // Every holding any day might be holding, so the closes are read once for the lot.
        $symbols = [];

        foreach ($this->accounts->where('type', AccountType::Security->value) as $broker) {
            foreach (array_keys(Positions::fromTrades($this->trades[$broker->id])) as $symbol) {
                $symbols[$symbol] = true;
            }
        }

        // Only the closes the days asked for can be answered by, not every close between
        // the first and the last: see seriesOn().
        $closeSeries = Price::seriesOn(array_keys($symbols), $inOrder);

        $snapshots = [];

        foreach ($inOrder as $day) {
            $snapshots[$day] = $this->snapshot($day, $balanceSeries[$day] ?? [], $closeSeries, $zero);
        }

        return array_map(fn (string $day) => $snapshots[$day], $days);
    }

    /**
     * One day's figures, from balances and closes already read.
     *
     * @param  array<int, string>  $balances
     * @param  array<string, array<string, array{close: string, ccy: string}>>  $closeSeries
     * @return array<string, mixed>
     */
    private function snapshot(string $day, array $balances, array $closeSeries, BigDecimal $zero): array
    {
        $totals = ['cash' => $zero, 'cards' => $zero, 'value' => $zero, 'cost' => $zero];
        $unpriced = 0;
        $unconverted = [];

        $cash = [];

        foreach ($this->accounts->whereIn('id', array_keys($balances)) as $account) {
            $balance = $balances[$account->id];

            if (BigDecimal::of($balance)->isZero()) {
                continue;
            }

            $base = $this->fx->toBase($balance, $account->ccy, $day);

            if ($base === null) {
                $unconverted[$account->ccy] = true;
            } else {
                $key = $account->type === AccountType::Card->value ? 'cards' : 'cash';
                $totals[$key] = $totals[$key]->plus($base);
            }

            $cash[] = [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'ccy' => $account->ccy,
                'balance' => $balance,
                'base' => $base === null ? null : (string) $base,
            ];
        }

        $brokerages = [];

        foreach ($this->accounts->where('type', AccountType::Security->value) as $broker) {
            $held = array_filter(
                Positions::fromTrades(array_values(array_filter(
                    $this->trades[$broker->id],
                    fn (array $trade) => $trade['date'] <= $day
                ))),
                fn (array $position) => $position['open']
            );

            if ($held === []) {
                continue;
            }

            $value = $cost = $zero;

            foreach ($held as $symbol => $position) {
                $price = $this->closeOn($symbol, $day, $closeSeries);
                $cost = $cost->plus($position['cost']);

                if ($price === null || $price['ccy'] !== $broker->ccy) {
                    $unpriced++;
                    $value = $value->plus($position['cost']);

                    continue;
                }

                $value = $value->plus(BigDecimal::of($position['quantity'])->multipliedBy($price['close']));
            }

            $value = $value->toScale(4, RoundingMode::HalfUp);
            $valueBase = $this->fx->toBase((string) $value, $broker->ccy, $day);
            $costBase = $this->fx->toBase((string) $cost, $broker->ccy, $day);

            if ($valueBase === null) {
                $unconverted[$broker->ccy] = true;
            } else {
                $totals['value'] = $totals['value']->plus($valueBase);
                $totals['cost'] = $totals['cost']->plus($costBase);
            }

            $brokerages[] = [
                'id' => $broker->id,
                'name' => $broker->name,
                'ccy' => $broker->ccy,
                'value' => (string) $value,
                'cost' => (string) $cost->toScale(4),
                'value_base' => $valueBase === null ? null : (string) $valueBase,
                'cost_base' => $costBase === null ? null : (string) $costBase,
                'unrealised' => (string) $value->minus($cost)->toScale(4),
                'unrealised_base' => $valueBase === null ? null : (string) $valueBase->minus($costBase),
            ];
        }

        $loans = [];
        $totals['loans'] = $zero;

        foreach ($this->loans->owedOn($day) as $loan) {
            $base = $this->fx->toBase((string) $loan['owed'], $loan['ccy'], $day);

            if ($base === null) {
                $unconverted[$loan['ccy']] = true;
            } else {
                $totals['loans'] = $totals['loans']->plus($base);
            }

            $loans[] = [
                'name' => $loan['name'],
                'ccy' => $loan['ccy'],
                'owed' => (string) $loan['owed']->toScale(4),
                'base' => $base === null ? null : (string) $base->toScale(4),
            ];
        }

        // The cards are not in this, and their absence is the point: see the class docblock.
        $net = $totals['cash']->plus($totals['value'])->minus($totals['loans']);

        // Four places throughout, the amount column's, so a zero reads 0.0000 as a balance does.
        $money = fn (BigDecimal $value) => (string) $value->toScale(4);

        return [
            'date' => $day,
            'net_worth' => $money($net),
            'cash' => $money($totals['cash']),
            'cards' => $money($totals['cards']),
            'loans' => $money($totals['loans']),
            'value' => $money($totals['value']),
            'cost' => $money($totals['cost']),
            'unrealised' => $money($totals['value']->minus($totals['cost'])),
            'accounts' => $cash,
            'brokerages' => $brokerages,
            'loan_rows' => $loans,
            'unpriced' => $unpriced,
            'unconverted' => array_keys($unconverted),
        ];
    }

    /**
     * A symbol's last close on or before a day, or null when it has none yet.
     *
     * Ascending by date, so the last row that is not after the day is the one wanted --
     * which is what a weekend or a holiday needs, and what latestFor() was doing a
     * query at a time.
     *
     * @param  array<string, array<string, array{close: string, ccy: string}>>  $closeSeries
     * @return array{close: string, ccy: string}|null
     */
    private function closeOn(string $symbol, string $day, array $closeSeries): ?array
    {
        $found = null;

        foreach ($closeSeries[$symbol] ?? [] as $date => $close) {
            if ($date > $day) {
                break;
            }

            $found = $close;
        }

        return $found;
    }

    /**
     * Today's snapshot and, per account, its figure at the last $months month ends and today,
     * oldest first, in its own currency: a balance, or a brokerage's market value. Read in
     * one onMany() so the accounts page's totals are the snapshot its lines end on.
     *
     * An account missing from a snapshot is zero, not absent: snapshot() leaves out a zero
     * balance and a brokerage holding nothing, and a line with a gap would start mid-chart.
     *
     * @return array{today: array<string, mixed>, trends: array<int, list<string>>}
     */
    public function accountTrends(Carbon $today, int $months = 12): array
    {
        $days = [];

        for ($n = $months; $n >= 1; $n--) {
            $days[] = $today->copy()->startOfMonth()->subMonthsNoOverflow($n - 1)->subDay()->toDateString();
        }

        $days[] = $today->toDateString();

        $snapshots = $this->onMany($days);
        $trends = [];

        foreach ($this->accounts as $account) {
            $trends[$account->id] = array_map(function (array $snapshot) use ($account) {
                $row = collect($account->type === AccountType::Security->value ? $snapshot['brokerages'] : $snapshot['accounts'])
                    ->firstWhere('id', $account->id);

                return $row[$account->type === AccountType::Security->value ? 'value' : 'balance'] ?? '0.0000';
            }, $snapshots);
        }

        return ['today' => end($snapshots), 'trends' => $trends];
    }

    /**
     * A snapshot at the end of every $months-month period back from today, and today's for
     * the period still running. Periods count from January, so a 12-month history is year
     * ends and a 3-month one quarter ends.
     *
     * Counted back from today rather than forward from the first transaction, because each
     * point is a full recomputation: walking the whole ledger answers a question about the
     * last few months in proportion to how old the data is, and discards all but the points
     * asked for. The first transaction is still the floor, so a ledger six weeks old does not
     * gain a run of empty months before it. $limit bounds the period ends, not the points,
     * so the caller keeps the most recent.
     *
     * @return list<array<string, string>>
     */
    public function history(int $months, Carbon $today, ?int $limit = null): array
    {
        return self::chartPoints($this->onMany($this->historyDays($months, $today, $limit)));
    }

    /**
     * The days history() takes its snapshots on, for a caller that wants other days too and
     * reads them all in one onMany(): each call is the balance aggregate and the closes
     * again. $from and $to are the first of the months the window starts and ends in, and
     * the end is that month's end or today, whichever comes first.
     *
     * @return list<string>
     */
    public function historyDays(int $months, Carbon $today, ?int $limit = null, ?string $from = null, ?string $to = null): array
    {
        // From the first whole year, as every page that counts back starts: see Ledger.
        $first = Ledger::start();

        if ($first === null) {
            return [];
        }

        // $from is a floor on the points, not a new place for the periods to count from:
        // counting from it would turn a yearly history into "every twelve months from
        // March", so a window would move where every year end falls.
        $start = $from !== null && $from > $first ? $from : $first;

        $todayString = $today->toDateString();

        /* The window opens on the end of the month it was given, so a start of January is
           drawn from January's end rather than from the first period end after it. Dropping
           to the next one left the control and the chart disagreeing -- from January with
           half-year points plotted from June, the control still reading January, and nothing
           saying which of the two the reader had asked for.

           The floor itself is not a point, which looks like an exception and is not: it is
           the first day the data can answer for, not a month anybody chose. */
        $opening = $start > $first ? Carbon::parse($start)->endOfMonth()->toDateString() : null;

        // The day the series finishes on: the window's last month end, or today where that
        // month is still running and has no end to read yet. A window ending after today is
        // not a window at all, and the prices for it do not exist.
        $monthEnd = $to === null ? $today->copy()->endOfMonth() : Carbon::parse($to)->endOfMonth();
        $end = $monthEnd->toDateString() < $todayString ? $monthEnd->toDateString() : $todayString;

        $points = [];
        $cursor = $today->copy()->startOfYear();

        // The last period end at or before today, so the count ends on a period boundary
        // rather than drifting by however many months into the year today falls.
        while ($cursor->copy()->addMonthsNoOverflow($months)->subDay()->toDateString() < $todayString) {
            $cursor->addMonthsNoOverflow($months);
        }

        for (; $cursor->copy()->subDay()->toDateString() >= $start; $cursor = $cursor->copy()->subMonthsNoOverflow($months)) {
            $day = $cursor->copy()->subDay()->toDateString();

            // Past the window's end, and the older ones below are past it too. At or before
            // the opening month as well, which is often a period end itself and would then
            // land on the chart twice.
            if ($day <= $end && ($opening === null || $day > $opening)) {
                $points[] = $day;
            }
        }

        if ($limit !== null && count($points) > $limit) {
            // From the front, because the list is newest first: slicing the tail kept the
            // OLDEST months, so a six-month trend off a ten-year ledger drew 2016.
            $points = array_slice($points, 0, $limit);
        }

        // Counted back from today, so newest first until here. Oldest first is the
        // contract: a caller reading the series plots or compares it in that order.
        $points = array_reverse($points);

        if ($opening !== null) {
            array_unshift($points, $opening);
        }

        // The end of the window is a point of its own, and one the periods may already have
        // produced: today on the last day of a month is a period end at every spacing that
        // divides a year, and a window ending on a month end is one too. Appending it either
        // way puts the same day on the chart twice, which reads as a flat step.
        if (end($points) !== $end) {
            $points[] = $end;
        }

        return $points;
    }

    /**
     * history()'s points from the snapshots on its days, cut to what a chart draws.
     *
     * @param  list<array<string, mixed>>  $snapshots
     * @return list<array<string, string>>
     */
    public static function chartPoints(array $snapshots): array
    {
        return array_map(
            fn (array $snapshot) => array_intersect_key(
                $snapshot,
                array_flip(['date', 'net_worth', 'cash', 'cards', 'loans', 'value', 'cost'])
            ),
            $snapshots
        );
    }
}
