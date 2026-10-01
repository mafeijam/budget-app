<?php

namespace App\Support;

use App\DTO\TransactionMetaData;
use App\Enums\AccountType;
use App\Enums\Frequency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Each cash account's balance, day by day from today, from what is already known to be
 * coming: rows dated ahead and rows still pending, the occurrences a recurring rule has
 * not written yet, and every card statement still owed, paid from the card's bank on its
 * due date.
 *
 * Everything is in the base currency at today's rate, the only one known for the days
 * ahead, so every cash account is on one chart.
 *
 * Nothing here is guessed except "typical spending" and "typical income", which are kept
 * apart: a daily figure each from the last year's, less what the recurring rules already
 * account for. The known figures never include them, so the page can show both.
 *
 * Dividends are left out of the known figures: they are irregular, and money not yet
 * declared is not money coming. They are an estimate of their own instead, expectedDividends(),
 * each holding's last year of payments a year on, into the account it paid; so typical
 * income is the year's income without them, or they would be counted twice.
 */
class Forecast
{
    /** The horizons offered, in months. */
    public const HORIZONS = [3, 6, 12];

    /**
     * The most a dividend on file may be from one expected for the expected one to be taken
     * as already counted: a payer's dates drift by days to weeks, never by a whole cycle.
     */
    private const DIVIDEND_WINDOW = 45;

    /** How far ahead the upcoming list reaches, in days. */
    public const UPCOMING_DAYS = 30;

    /** @var Collection<int, Account> */
    private Collection $cash;

    /** @var list<array{date: string, account_id: int, amount: BigDecimal, description: string, kind: string, link: array<string, mixed>}> */
    private array $events = [];

    /** @var list<array{ccy: string, message: string}> */
    private array $warnings = [];

    /**
     * The cash flow of this month and the twelve complete ones before it, read once. Every
     * typical figure is taken over the twelve, through completeMonths(); this month is only
     * ever "so far".
     *
     * Both typicalSpending() and monthOutlook() need it, for the same $today, and it is a
     * full scan of a year of rows -- so calling it twice is half a second of the page
     * waiting for an answer it already has. Memoised rather than passed as an argument
     * because the two callers are unrelated, and a parameter would tie them together for
     * the sake of a cache.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $lastMonths = null;

    /** @var list<array{account: Account, monthly: BigDecimal}>|null read once, as is */
    private ?array $cardTypical = null;

    /** @var list<array{date: string, account_id: int, amount: BigDecimal, symbol: string, paid: string, transaction: int}> */
    private array $expected = [];

    private Fx $fx;

    public function __construct(private Carbon $today, private Carbon $end, bool $onlyDividends = false)
    {
        $this->cash = Account::query()
            ->where('type', AccountType::Cash->value)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        // The dividends page wants the expected dividends and nothing else, and they read
        // only the cash accounts: the statements, rules and rows are most of a forecast's
        // cost, for figures that page never shows.
        if ($onlyDividends) {
            $this->expectedDividends();

            return;
        }

        $this->fx = Fx::for(Account::query()->distinct()->pluck('ccy')->all());

        $this->knownRows();
        $this->recurring();
        $this->statements();
        $this->expectedDividends();
        $this->expectedBonuses();
        $this->expectedDoublePay();

        usort($this->events, fn (array $a, array $b) => [$a['date'], $a['description']] <=> [$b['date'], $b['description']]);
    }

    public static function for(Carbon $today, int $months): self
    {
        return new self($today->copy()->startOfDay(), $today->copy()->startOfDay()->addMonthsNoOverflow($months));
    }

    /**
     * expectedDividendList() of for(), without the rest of the forecast built to get there.
     *
     * @return list<array{date: string, account_id: int, broker: int|null, ccy: string, symbol: string, amount: string, paid: string}>
     */
    public static function expectedDividendsFor(Carbon $today, int $months): array
    {
        return (new self($today->copy()->startOfDay(), $today->copy()->startOfDay()->addMonthsNoOverflow($months), onlyDividends: true))
            ->expectedDividendList();
    }

    /**
     * One entry per currency: the daily totals with and without typical spending, and each
     * account's opening, lowest and closing balance.
     *
     * @return list<array<string, mixed>>
     */
    public function projection(?string $only = null): array
    {
        $day = $this->today->toDateString();
        $shownIn = $only ?? Fx::BASE->value;
        $openingBalances = AccountBalance::forAccounts($this->cash, $day);
        $typical = $this->typicalSpending();
        $moving = collect($this->events)->pluck('account_id')->unique()->all();

        // Each account at today's rate into the base currency, the only rate known for the
        // days ahead. One with no rate yet is left out and named, as on the net worth page.
        $rates = [];
        $balances = [];

        foreach ($this->cash as $account) {
            $balance = BigDecimal::of($openingBalances[$account->id] ?? '0');

            // An account with nothing in it and nothing coming is left off the page.
            if ($balance->isZero() && ! in_array($account->id, $moving, true)) {
                continue;
            }

            if ($only !== null && $account->ccy !== $only) {
                continue;
            }

            // In its own currency when one is picked, so nothing converts at all.
            $rate = $only === null ? $this->fx->rate($account->ccy, $day) : '1';

            if ($rate === null) {
                $this->warnings[] = ['ccy' => $account->ccy, 'message' => sprintf(
                    'No %s rate yet, so [%s] is left out. Fetch prices on the Positions page.',
                    $account->ccy,
                    $account->name
                )];

                continue;
            }

            $rates[$account->id] = BigDecimal::of($rate);
            $balances[$account->id] = $balance;
        }

        if ($balances === []) {
            return [];
        }

        $base = fn (int $id, BigDecimal $amount) => $amount->multipliedBy($rates[$id])->toScale(4, RoundingMode::HalfUp);

        $monthly = $average = $covered = $cashMonthly = $cardMonthly = $irregularMonthly = BigDecimal::zero();
        $incomeMonthly = $incomeAverage = $incomeCovered = $incomeDividends = $incomeMedian = BigDecimal::zero();

        foreach ($typical as $ccy => $figures) {
            if ($only !== null && $ccy !== $only) {
                continue;
            }

            $rate = $only === null ? $this->fx->rate($ccy, $day) : '1';

            if ($rate === null) {
                continue;
            }

            $monthly = $monthly->plus(BigDecimal::of($figures['monthly'])->multipliedBy($rate));
            $cashMonthly = $cashMonthly->plus(BigDecimal::of($figures['cash'])->multipliedBy($rate));
            $irregularMonthly = $irregularMonthly->plus(BigDecimal::of($figures['irregular'])->multipliedBy($rate));
            $incomeMonthly = $incomeMonthly->plus(BigDecimal::of($figures['income'])->multipliedBy($rate));
            $incomeAverage = $incomeAverage->plus(BigDecimal::of($figures['income_average'])->multipliedBy($rate));
            $incomeCovered = $incomeCovered->plus(BigDecimal::of($figures['income_recurring'])->multipliedBy($rate));
            $incomeDividends = $incomeDividends->plus(BigDecimal::of($figures['income_dividends'])->multipliedBy($rate));
            $incomeMedian = $incomeMedian->plus(BigDecimal::of($figures['income_median'])->multipliedBy($rate));
            $average = $average->plus(BigDecimal::of($figures['average'])->multipliedBy($rate));
            $covered = $covered->plus(BigDecimal::of($figures['recurring'])->multipliedBy($rate));
        }

        $perDay = fn (BigDecimal $month) => $month->multipliedBy(12)->dividedBy(365, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
        $dailyCash = $perDay($cashMonthly);
        $dailyIncome = $perDay($incomeMonthly);
        // An allowance and not a payment, so it is spread as cash spending is rather than
        // guessed onto a card's due date: the year it averages over paid both ways.
        $dailyIrregular = $perDay($irregularMonthly);

        $lowest = array_map(fn (BigDecimal $balance) => [$balance, $day], $balances);
        $byDay = collect($this->events)->whereIn('account_id', array_keys($balances))->groupBy('date');
        $expectedByDay = collect($this->expected)->whereIn('account_id', array_keys($balances))->groupBy('date');
        $expectedBy = array_map(fn () => BigDecimal::zero(), $balances);
        // Of $expectedBy, each account's share by kind: a salary account's double pay is not a
        // dividend, and labelled one it put 42,330 of "dividends" on SAVING.
        $expectedKinds = array_map(fn () => ['dividends' => BigDecimal::zero(), 'bonuses' => BigDecimal::zero(), 'double_pay' => BigDecimal::zero()], $balances);
        // Each account at today and every month end, known and with its expected dividends.
        $paths = [];

        // Each card's typical charges, a day's worth from tomorrow, paid on the due date of
        // the statement that day falls in -- as a recurring card charge is. The statements
        // already known pay for what was charged up to today, so nothing here repeats them,
        // and a charge made before a statement closes is on that statement, not the next.
        $cardDue = [];

        foreach ($this->cardTypical() as $card) {
            $bank = $card['account']->settlementAccount();
            $cycle = CardStatementCycle::fromMeta($card['account']->meta?->meta);

            if ($bank === null || $cycle === null || ! isset($balances[$bank->id])) {
                continue;
            }

            if ($only !== null && $card['account']->ccy !== $only) {
                continue;
            }

            $rate = $only === null ? $this->fx->rate($card['account']->ccy, $day) : '1';

            if ($rate === null) {
                continue;
            }

            $monthlyBase = $card['monthly']->multipliedBy($rate);
            $cardMonthly = $cardMonthly->plus($monthlyBase);
            $perCharge = $perDay($monthlyBase);

            for ($charged = $this->today->copy()->addDay(); $charged->lessThanOrEqualTo($this->end); $charged->addDay()) {
                $due = $cycle->dueDateFor($charged->copy())->toDateString();

                if ($due <= $this->end->toDateString()) {
                    $cardDue[$due] = ($cardDue[$due] ?? BigDecimal::zero())->plus($perCharge);
                }
            }
        }

        $points = [];
        $allowance = BigDecimal::zero();
        $irregular = BigDecimal::zero();
        $earned = BigDecimal::zero();
        // Of $earned, the part spread day by day; the rest is the estimates on their dates.
        $spread = BigDecimal::zero();
        $lowestTotal = null;
        $zero = BigDecimal::zero();

        // Each event with the balance it leaves, and each month's known money in and out.
        $events = [];
        $monthsAhead = [];
        $lowestAhead = ['known' => null, 'typical' => null];
        $recurringIn = $zero;
        $running = $zero;

        foreach ($balances as $id => $balance) {
            $running = $running->plus($base($id, $balance));
        }

        for ($cursor = $this->today->copy(); $cursor->lessThanOrEqualTo($this->end); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $month = substr($date, 0, 7);
            $monthsAhead[$month] ??= ['month' => $month, 'in' => $zero, 'out' => $zero, 'typical' => $zero, 'irregular' => $zero, 'typical_in' => $zero, 'dividends' => $zero, 'bonuses' => $zero, 'double_pay' => $zero];

            // From tomorrow: today's spending is in today's balance already.
            if ($cursor->greaterThan($this->today)) {
                $daily = $dailyCash->plus($cardDue[$date] ?? BigDecimal::zero());
                $allowance = $allowance->plus($daily);
                $irregular = $irregular->plus($dailyIrregular);
                $earned = $earned->plus($dailyIncome);
                $spread = $spread->plus($dailyIncome);
                // In the month's typical spending, and its own figure of it as well.
                $monthsAhead[$month]['typical'] = $monthsAhead[$month]['typical']->plus($daily)->plus($dailyIrregular);
                $monthsAhead[$month]['irregular'] = $monthsAhead[$month]['irregular']->plus($dailyIrregular);
                $monthsAhead[$month]['typical_in'] = $monthsAhead[$month]['typical_in']->plus($dailyIncome);
            }

            foreach ($byDay[$date] ?? [] as $event) {
                $balances[$event['account_id']] = $balances[$event['account_id']]->plus($event['amount']);

                $amount = $base($event['account_id'], $event['amount']);
                $running = $running->plus($amount);
                $side = $amount->isNegative() ? 'out' : 'in';
                $monthsAhead[$month][$side] = $monthsAhead[$month][$side]->plus($amount->abs());

                // What the page's what-if takes away when it asks for no recurring income.
                if ($event['kind'] === 'recurring' && $amount->isPositive()) {
                    $recurringIn = $recurringIn->plus($amount);
                }

                $events[] = [
                    'date' => $date,
                    'account_id' => $event['account_id'],
                    'account' => $this->cash[$event['account_id']]->name,
                    'ccy' => $this->cash[$event['account_id']]->ccy,
                    'amount' => self::money($event['amount']),
                    'base' => self::money($amount),
                    'description' => $event['description'],
                    'kind' => $event['kind'],
                    'link' => $event['link'],
                    'balance' => self::money($running),
                    'with_typical' => self::money($running->minus($allowance)->minus($irregular)->plus($earned)),
                ];
            }

            // An estimate, so on the typical line only, as typical income is: the known balance
            // does not move, and the row says it is expected rather than coming. A bonus and
            // a month's double pay land the same way, on the date they were paid rather than
            // spread across the year. Each says which column of the month it belongs in and
            // how it reads, so adding a third kind costs nothing here.
            foreach ($expectedByDay[$date] ?? [] as $expected) {
                $amount = $base($expected['account_id'], $expected['amount']);
                $earned = $earned->plus($amount);
                $expectedBy[$expected['account_id']] = $expectedBy[$expected['account_id']]->plus($expected['amount']);
                $expectedKinds[$expected['account_id']][$expected['bucket']] = $expectedKinds[$expected['account_id']][$expected['bucket']]->plus($expected['amount']);
                $monthsAhead[$month]['typical_in'] = $monthsAhead[$month]['typical_in']->plus($amount);
                $monthsAhead[$month][$expected['bucket']] = $monthsAhead[$month][$expected['bucket']]->plus($amount);

                $events[] = [
                    'date' => $date,
                    'account_id' => $expected['account_id'],
                    'account' => $this->cash[$expected['account_id']]->name,
                    'ccy' => $this->cash[$expected['account_id']]->ccy,
                    'amount' => self::money($expected['amount']),
                    'base' => self::money($amount),
                    'description' => $expected['description'],
                    'kind' => $expected['kind'],
                    'link' => ['transaction' => $expected['transaction']],
                    'balance' => self::money($running),
                    'with_typical' => self::money($running->minus($allowance)->minus($irregular)->plus($earned)),
                    'estimate' => true,
                ];
            }

            $total = BigDecimal::zero();

            foreach ($balances as $id => $balance) {
                if ($balance->isLessThan($lowest[$id][0])) {
                    $lowest[$id] = [$balance, $date];
                }

                $total = $total->plus($base($id, $balance));
            }

            if ($lowestTotal === null || $total->isLessThan($lowestTotal[0])) {
                $lowestTotal = [$total, $date];
            }

            // After today, since today's balance is already a fact and the lowest of every
            // horizon that only rises from here.
            if ($cursor->greaterThan($this->today)) {
                foreach (['known' => $total, 'typical' => $total->minus($allowance)->minus($irregular)->plus($earned)] as $line => $value) {
                    if ($lowestAhead[$line] === null || $value->isLessThan($lowestAhead[$line][0])) {
                        $lowestAhead[$line] = [$value, $date];
                    }
                }
            }

            if ($cursor->equalTo($this->today) || $cursor->isLastOfMonth() || $cursor->equalTo($this->end)) {
                foreach ($balances as $id => $balance) {
                    $paths[$id][] = [
                        'known' => self::money($base($id, $balance)),
                        'expected' => self::money($base($id, $balance->plus($expectedBy[$id]))),
                    ];
                }
            }

            $monthsAhead[$month]['end_known'] = $total;
            $monthsAhead[$month]['end_typical'] = $total->minus($allowance)->minus($irregular)->plus($earned);

            $points[] = [
                'date' => $date,
                'known' => self::money($total),
                'typical' => self::money($total->minus($allowance)->minus($irregular)->plus($earned)),
                // Running totals, so the page's what-if can redraw without the server. The
                // irregular spending is apart from the allowance so the page can take it away.
                'allowance' => self::money($allowance),
                'irregular' => self::money($irregular),
                'earned' => self::money($earned),
                'recurring_in' => self::money($recurringIn),
            ];
        }

        $opening = $points[0]['known'];
        $closing = BigDecimal::of(end($points)['typical']);
        $horizon = max(1, (int) round($this->today->diffInMonths($this->end)));

        return [[
            'ccy' => $shownIn,
            'points' => $points,
            'typical_monthly' => self::money($monthly),
            'typical_income' => self::money($incomeMonthly),
            'typical_income_basis' => [
                'average' => self::money($incomeAverage),
                'median' => self::money($incomeMedian),
                'recurring' => self::money($incomeCovered),
                'dividends' => self::money($incomeDividends),
                // The monthly figure over the horizon, from tomorrow: with the dividends, the
                // bonus and the double pay below it is everything the typical line earns.
                'spread' => self::money($spread),
            ],
            // What the holdings are expected to pay over the whole horizon, on the typical line.
            'expected_dividends' => self::money(collect($events)
                ->where('kind', 'expected dividend')
                ->reduce(fn (BigDecimal $sum, array $e) => $sum->plus($e['base']), BigDecimal::zero())),
            // And the bonuses, placed on the dates they were paid rather than spread.
            'expected_bonuses' => self::money(collect($events)
                ->where('kind', 'expected bonus')
                ->reduce(fn (BigDecimal $sum, array $e) => $sum->plus($e['base']), BigDecimal::zero())),
            // And the second month's pay, on the January it was last paid.
            'expected_double_pay' => self::money(collect($events)
                ->where('kind', 'expected double pay')
                ->reduce(fn (BigDecimal $sum, array $e) => $sum->plus($e['base']), BigDecimal::zero())),
            'typical_basis' => [
                'average' => self::money($average),
                'recurring' => self::money($covered),
                'cash' => self::money($cashMonthly),
                'card' => self::money($cardMonthly),
                'irregular' => self::money($irregularMonthly),
            ],
            'lowest' => ['amount' => self::money($lowestTotal[0]), 'date' => $lowestTotal[1]],
            'lowest_ahead' => array_map(
                fn (?array $low) => $low === null ? null : ['amount' => self::money($low[0]), 'date' => $low[1]],
                $lowestAhead
            ),
            // What the balance gains a month with typical spending, over the whole horizon.
            'saving_monthly' => self::money($closing->minus($opening)->dividedBy($horizon, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp)),
            // How many months today's cash would last spending the year's average and earning
            // nothing: null when there is no spending to divide by.
            'runway_months' => $average->isPositive()
                ? (string) BigDecimal::of($opening)->dividedBy($average, 1, RoundingMode::Down)
                : null,
            'months' => array_values(array_map(fn (array $m) => [
                'month' => $m['month'],
                'in' => self::money($m['in']),
                'out' => self::money($m['out']),
                'typical' => self::money($m['typical']),
                // Of typical, the irregular spending.
                'irregular' => self::money($m['irregular']),
                'typical_in' => self::money($m['typical_in']),
                // Of typical_in, what the holdings are expected to pay, the bonus expected on
                // the date it was paid, and the second month's pay likewise.
                'dividends' => self::money($m['dividends']),
                'bonuses' => self::money($m['bonuses']),
                'double_pay' => self::money($m['double_pay']),
                'net_known' => self::money($m['in']->minus($m['out'])),
                'net_typical' => self::money($m['in']->minus($m['out'])->minus($m['typical'])->plus($m['typical_in'])),
                'end_known' => self::money($m['end_known']),
                'end_typical' => self::money($m['end_typical']),
            ], $monthsAhead)),
            'events' => $events,
            'accounts' => collect($balances)->map(function (BigDecimal $closing, int $id) use ($openingBalances, $lowest, $base, $expectedBy, $expectedKinds, $paths) {
                $start = BigDecimal::of($openingBalances[$id] ?? '0');
                $expected = $expectedBy[$id];

                return [
                    'id' => $id,
                    'name' => $this->cash[$id]->name,
                    'ccy' => $this->cash[$id]->ccy,
                    'opening' => self::money($base($id, $start)),
                    'closing' => self::money($base($id, $closing)),
                    'lowest' => ['amount' => self::money($base($id, $lowest[$id][0])), 'date' => $lowest[$id][1]],
                    // Everything expected into it by the end -- dividends, a bonus, a double pay --
                    // and the closing with it: an estimate beside the known closing, never
                    // folded into it. Each kind is its own figure, for the label to name.
                    'expected' => self::money($base($id, $expected)),
                    'dividends' => self::money($base($id, $expectedKinds[$id]['dividends'])),
                    'bonuses' => self::money($base($id, $expectedKinds[$id]['bonuses'])),
                    'double_pay' => self::money($base($id, $expectedKinds[$id]['double_pay'])),
                    'closing_expected' => self::money($base($id, $closing->plus($expected))),
                    'path' => $paths[$id] ?? [],
                    // In the account's own currency, for the one not held in the base.
                    'native' => [
                        'opening' => self::money($start),
                        'closing' => self::money($closing),
                        'lowest' => self::money($lowest[$id][0]),
                        'dividends' => self::money($expectedKinds[$id]['dividends']),
                        'closing_expected' => self::money($closing->plus($expected)),
                    ],
                ];
            })->values()->all(),
        ]];
    }

    /**
     * The dividends expected by the end, earliest first, each in its account's own currency:
     * the estimate the projection puts on its typical line, for the dividends page to show
     * beside what has been paid.
     *
     * `broker` is the brokerage whose holding paid the dividend it is derived from, or null:
     * a bonus or a double pay is no holding's, and a dividend with no brokerage named has none.
     *
     * @return list<array{date: string, account_id: int, broker: int|null, ccy: string, symbol: string, amount: string, paid: string}>
     */
    public function expectedDividendList(): array
    {
        return array_map(fn (array $d) => [
            'date' => $d['date'],
            'account_id' => $d['account_id'],
            'broker' => $d['broker'] ?? null,
            'ccy' => $this->cash[$d['account_id']]->ccy,
            'symbol' => $d['symbol'],
            'amount' => self::money($d['amount']),
            'paid' => $d['paid'],
        ], $this->expected);
    }

    /**
     * The currencies the cash accounts are in, for the page's picker.
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        return $this->cash->pluck('ccy')->unique()->sort()->values()->all();
    }

    /**
     * The known movements in the next UPCOMING_DAYS days, earliest first.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(?string $only = null): array
    {
        $until = $this->today->copy()->addDays(self::UPCOMING_DAYS)->toDateString();
        $named = fn (int $accountId) => $this->cash[$accountId];

        $known = collect($this->events)
            ->filter(fn (array $event) => $event['date'] <= $until)
            ->map(fn (array $event) => [
                ...$event,
                'amount' => self::money($event['amount']),
                'account' => $named($event['account_id'])->name,
                'ccy' => $named($event['account_id'])->ccy,
            ]);

        // An expected dividend, a bonus or a double pay is money coming too, and it is what
        // this panel is for: a dividend expected on the 2nd sits inside a fourteen-day window,
        // and leaving it out said the fortnight was emptier than it is. It is flagged an
        // estimate because it moves the typical line only -- the known line knows nothing of
        // it, which is the whole difference the projection draws between the two. `paid` is
        // the row it was derived from, so a click on it finds that row and not every payment
        // on the same holding.
        $estimates = collect($this->expected)
            ->filter(fn (array $event) => $event['date'] <= $until)
            ->map(fn (array $event) => [
                'date' => $event['date'],
                'account_id' => $event['account_id'],
                'amount' => self::money($event['amount']),
                'account' => $named($event['account_id'])->name,
                'ccy' => $named($event['account_id'])->ccy,
                'description' => $event['description'],
                'kind' => $event['kind'],
                'link' => ['transaction' => $event['transaction'], 'date' => $event['paid']],
                'estimate' => true,
            ]);

        // By date, which nothing did before: the events arrive in the order they were
        // gathered -- rows, then rules, then statements -- so a panel holding more than one
        // kind was never in date order, and merging a fourth kind in only made that visible.
        return $known->concat($estimates)
            ->filter(fn (array $event) => $only === null || $event['ccy'] === $only)
            ->sortBy('date')
            ->values()
            ->all();
    }

    /**
     * Per currency: this month so far, what is known still to come before it ends, typical
     * spending for the days left, and the likely net at month end beside the year's average.
     *
     * So far is CashFlow's month, which already counts a posted row dated later this month
     * and a card charge on the due date of the statement it falls in. To come is what that
     * cannot see yet, cash pending rows and cash occurrences not written; a card charge is
     * left out of it entirely, because the statement already carries that charge to the
     * month it is paid in. A statement payment is not spending at all, as in the report.
     *
     * @return list<array<string, mixed>>
     */
    public function monthOutlook(?string $only = null): array
    {
        $monthEnd = $this->today->copy()->endOfMonth()->toDateString();
        $daysLeft = (int) $this->today->diffInDays($this->today->copy()->endOfMonth());
        $daysInMonth = $this->today->daysInMonth;
        $typical = $this->typicalSpending();
        $zero = BigDecimal::zero();

        $toCome = [];
        $bump = function (string $ccy, string $kind, BigDecimal $amount) use (&$toCome, $zero) {
            $toCome[$ccy][$kind] = ($toCome[$ccy][$kind] ?? $zero)->plus($amount);
        };

        $pending = Transaction::query()
            ->with('account')
            ->where('status', TransactionStatus::Pending->value)
            // This month's only: posted, an earlier one counts in its own month.
            ->whereBetween('date', [$this->today->copy()->startOfMonth()->toDateString(), $monthEnd])
            // Cash only, because a card's pending charge reaches the bank with its statement.
            ->whereHas('account', fn ($q) => $q->where('type', AccountType::Cash->value))
            ->get();

        foreach ($pending as $row) {
            $this->classifyToCome($row->account, $row->type, (string) $row->amount, $bump);
        }

        foreach (RecurringTransaction::query()->where('active', true)->with('account')->get() as $rule) {
            foreach ($rule->dueThrough($this->today->copy()->endOfMonth()) as $date) {
                $this->classifyToCome($rule->account, $rule->type, (string) $rule->amount, $bump);
            }
        }

        $report = [];
        $months = collect($this->lastMonths())->keyBy('ccy');

        foreach ($months as $ccy => $section) {
            $now = collect($section['months'])->last();
            $income = BigDecimal::of($now['income']);
            $spending = BigDecimal::of($now['spending']);

            $comingIn = $toCome[$ccy]['income'] ?? $zero;
            $comingOut = $toCome[$ccy]['spending'] ?? $zero;
            // Cash only: a card charge made in the days left is paid for next month or later.
            // The irregular spending as the projection spreads it, so the month end is the estimate
            // the chart draws.
            $typicalRest = BigDecimal::of($typical[$ccy]['cash'] ?? '0')
                ->plus($typical[$ccy]['irregular'] ?? '0')
                ->multipliedBy($daysLeft)
                ->dividedBy($daysInMonth, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);

            $typicalIncomeRest = BigDecimal::of($typical[$ccy]['income'] ?? '0')
                ->multipliedBy($daysLeft)
                ->dividedBy($daysInMonth, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp)
                ->plus($this->dividendsThrough($ccy, $monthEnd));

            $known = $income->plus($comingIn)->minus($spending)->minus($comingOut);

            $report[] = [
                'ccy' => $ccy,
                'month' => $now['month'],
                'days_left' => $daysLeft,
                'so_far' => ['income' => self::money($income), 'spending' => self::money($spending), 'net' => self::money($income->minus($spending))],
                'to_come' => ['income' => self::money($comingIn), 'spending' => self::money($comingOut)],
                'typical_rest' => self::money($typicalRest),
                'typical_income_rest' => self::money($typicalIncomeRest),
                'likely_known' => self::money($known),
                'likely_net' => self::money($known->minus($typicalRest)->plus($typicalIncomeRest)),
                'average_net' => self::money(self::monthlyMean(array_map(self::ordinary(...), self::completeMonths($section)), 'net')),
            ];
        }

        if ($only !== null) {
            return array_values(array_filter($report, fn (array $row) => $row['ccy'] === $only));
        }

        return $this->combined($report);
    }

    /**
     * The outlook's rows as one, in the base currency at today's rate. A currency with no
     * rate yet is left out, as the projection leaves it out.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function combined(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $day = $this->today->toDateString();
        $paths = ['so_far.income', 'so_far.spending', 'so_far.net', 'to_come.income', 'to_come.spending', 'typical_rest', 'typical_income_rest', 'likely_known', 'likely_net', 'average_net'];
        $sums = array_fill_keys($paths, BigDecimal::zero());

        foreach ($rows as $row) {
            $rate = $this->fx->rate($row['ccy'], $day);

            if ($rate === null) {
                continue;
            }

            foreach ($paths as $path) {
                $sums[$path] = $sums[$path]->plus(BigDecimal::of(data_get($row, $path))->multipliedBy($rate));
            }
        }

        $combined = ['ccy' => Fx::BASE->value, 'month' => $rows[0]['month'], 'days_left' => $rows[0]['days_left']];

        foreach ($sums as $path => $sum) {
            data_set($combined, $path, self::money($sum));
        }

        return [$combined];
    }

    /** Cash money in or spent this month, as the cash flow report reads it; a card is not here. */
    private function classifyToCome(?Account $account, string $type, string $amount, callable $bump): void
    {
        if ($account === null) {
            return;
        }

        $sign = TransactionType::from($type)->movesBalanceOn(AccountType::from($account->type));

        // A card is not a here: the statement carries the charge to its due date, which is
        // the month the money leaves the bank. `so_far` counts it there and the projection
        // places it there, so counting it on the charge's own month as well would charge
        // this one twice and put it in a month it never leaves in.
        if ($account->type === AccountType::Card->value) {
            return;
        }

        if ($sign !== 0) {
            $bump($account->ccy, $sign > 0 ? 'income' : 'spending', BigDecimal::of($amount));
        }
    }

    /** @return list<string> */
    public function warnings(?string $only = null): array
    {
        return array_values(array_map(
            fn (array $warning) => $warning['message'],
            array_filter($this->warnings, fn (array $warning) => $only === null || $warning['ccy'] === $only)
        ));
    }

    // ---------------------------------------------------------------------
    // What is known to be coming
    // ---------------------------------------------------------------------

    /**
     * Rows on a cash account dated after today, and pending ones, which post today at the
     * earliest.
     *
     * A settlement's bank half is not one of them, and that exclusion is the half a type
     * list cannot express: a card payment is a withdraw on the bank and looks like any
     * other. It is the money leaving for a statement this page already carries as a
     * statement event, and a settlement already made is not coming up at all. Same rule
     * and the same reason as CashFlow::whereSpends(), reached through that one place so
     * the two cannot drift -- a second copy of "is this settling a card" is exactly the
     * kind of restatement that outlives the reason for it.
     */
    private function knownRows(): void
    {
        $rows = Transaction::query()
            ->whereIn('account_id', $this->cash->keys())
            ->where('date', '<=', $this->end->toDateString())
            ->where(fn ($q) => $q
                ->where('date', '>', $this->today->toDateString())
                ->orWhere('status', TransactionStatus::Pending->value))
            ->whereNotIn('id', CashFlow::whereSettlesACard(DB::table('transactions')))
            ->get();

        foreach ($rows as $row) {
            $this->add(
                max($row->date, $this->today->toDateString()),
                $row->account_id,
                $row->type,
                (string) $row->amount,
                $row->description,
                $row->status === TransactionStatus::Pending->value ? 'pending' : 'scheduled',
                // The row's own date, not the one it is listed under: a pending row from an
                // earlier day is listed under today, and a filter on the listed day would
                // find nothing. The transaction id is here for the same reason -- a click
                // that cannot address the one row it was about.
                ['transaction' => $row->id, 'date' => $row->date]
            );
        }
    }

    /**
     * The occurrences each active rule has still to write. One that should already have
     * been written is counted today, the soonest it can now land.
     */
    private function recurring(): void
    {
        $rules = RecurringTransaction::query()->where('active', true)->with('account.meta')->get();
        $banks = [];

        foreach ($rules as $rule) {
            foreach ($rule->dueThrough($this->end) as $date) {
                $date = max($date, $this->today->toDateString());

                if ($rule->account?->type === AccountType::Cash->value) {
                    $this->add($date, $rule->account_id, $rule->type, (string) $rule->amount, $rule->description, 'recurring', ['recurring' => $rule->id]);

                    continue;
                }

                // A card charge reaches the bank when the statement it falls in is paid.
                if ($rule->account?->type === AccountType::Card->value && $rule->type === TransactionType::Charge->value) {
                    $cycle = CardStatementCycle::fromMeta($rule->account->meta?->meta);
                    // Once per card, not per date: a query each, and a weekly rule over a
                    // year is fifty of them.
                    $bank = array_key_exists($rule->account_id, $banks)
                        ? $banks[$rule->account_id]
                        : ($banks[$rule->account_id] = $rule->account->settlementAccount());

                    if ($cycle === null || $bank === null || ! $this->cash->has($bank->id)) {
                        continue;
                    }

                    $due = $cycle->dueDateFor(Carbon::parse($date))->toDateString();

                    if ($due <= $this->end->toDateString()) {
                        $this->add(
                            max($due, $this->today->toDateString()),
                            $bank->id,
                            TransactionType::Withdraw->value,
                            (string) ($rule->card_amount ?? $rule->amount),
                            "{$rule->description} [{$rule->account->name}]",
                            'recurring',
                            ['recurring' => $rule->id]
                        );
                    }
                }
            }
        }
    }

    /**
     * Every card statement still owing, pending charges included, as a withdrawal from the
     * card's bank on its due date, or today once it is overdue.
     */
    private function statements(): void
    {
        $cards = Account::query()->where('type', AccountType::Card->value)->with('meta')->orderBy('name')->get();
        $periods = CardStatement::forAccounts($cards);

        foreach ($cards as $card) {
            $bank = $card->settlementAccount();

            foreach ($periods[$card->id] ?? collect() as $statement) {
                if ($statement->dueDate > $this->end->toDateString()) {
                    continue;
                }

                $owed = BigDecimal::of($statement->owed());

                // Only fetched when the aggregate says there is something to fetch.
                // rowsInPeriod() is two queries, and a settled period's pending total is
                // always zero, so asking for it on all of them is a query per statement
                // that can only return nothing -- a cost that reads as a slow page once
                // the ledger holds years of statements rather than a season of them.
                if ($statement->pendingCount > 0) {
                    $owed = $owed->plus(
                        CardStatement::rowsInPeriod($card, $statement->dueDate)
                            ->filter(fn (Transaction $row) => $row->status === TransactionStatus::Pending->value
                                && $row->type === TransactionType::Charge->value)
                            ->reduce(fn (BigDecimal $sum, Transaction $row) => $sum->plus(
                                $row->meta?->meta['card_amount'] ?? $row->amount
                            ), BigDecimal::zero())
                    );
                }

                if (! $owed->isPositive()) {
                    continue;
                }

                if ($bank === null || ! $this->cash->has($bank->id)) {
                    $this->warnings[] = ['ccy' => $card->ccy, 'message' => sprintf(
                        'Card [%s] owes %s %s due %s but names no bank to pay it from, so it is not in any balance here.',
                        $card->name,
                        self::money($owed),
                        $card->ccy,
                        $statement->dueDate
                    )];

                    continue;
                }

                $this->events[] = [
                    'date' => max($statement->dueDate, $this->today->toDateString()),
                    'account_id' => $bank->id,
                    'amount' => $owed->negated(),
                    'description' => "{$card->name} statement due {$statement->dueDate}",
                    'kind' => 'statement',
                    'link' => ['card' => $card->id, 'due_date' => $statement->dueDate],
                ];
            }
        }
    }

    /** A movement on a cash account, signed by the type as a balance would sign it. */
    /**
     * The dividends each holding is expected to pay by the end: every dividend a cash account
     * was paid in the last year, a year on, in proportion to the shares held now against the
     * shares held when it was paid. A symbol sold out since pays nothing.
     *
     * One is left out when another dividend on the symbol into the same account is on file
     * near the day it is expected: paid a little early this year, or declared and entered
     * ahead, and either way already counted. Near is half the symbol's usual gap between
     * payments, at most DIVIDEND_WINDOW days: a fixed 45 read a monthly payer's last payment
     * as this one, and expected nothing from it at all.
     */
    private function expectedDividends(): void
    {
        $from = $this->today->copy()->subYearNoOverflow();
        $rows = Transaction::query()
            ->with('meta')
            ->whereIn('account_id', $this->cash->keys())
            ->where('type', TransactionType::Dividend->value)
            ->where('date', '>', $from->copy()->subDays(self::DIVIDEND_WINDOW)->toDateString())
            ->get()
            ->reject(self::oneOff(...))
            ->map(fn (Transaction $row) => [
                'id' => $row->id,
                'date' => $row->date,
                'account_id' => $row->account_id,
                'amount' => (string) $row->amount,
                'symbol' => Positions::symbol($row->meta?->meta['symbol'] ?? ''),
                'broker' => $row->meta?->meta['brokerage_account_id'] ?? null,
            ]);

        // The median gap between one account's payments on a symbol, in days.
        $windows = $rows->groupBy(fn (array $row) => $row['account_id'].'|'.$row['symbol'])
            ->map(function (Collection $payments) {
                $dates = $payments->pluck('date')->sort()->values();
                $gaps = $dates->slice(1)->values()
                    ->map(fn (string $date, int $i) => Carbon::parse($dates[$i])->diffInDays(Carbon::parse($date)))
                    ->filter(fn (float $gap) => $gap > 0)
                    ->sort()
                    ->values();

                return $gaps->isEmpty()
                    ? self::DIVIDEND_WINDOW
                    : min(self::DIVIDEND_WINDOW, intdiv((int) $gaps[intdiv($gaps->count(), 2)], 2));
            });

        $trades = [];
        $held = function (?int $broker, string $symbol, string $on) use (&$trades): ?BigDecimal {
            if ($broker === null) {
                return null;
            }

            $trades[$broker] ??= ($account = Account::find($broker)) ? Positions::tradesOf($account) : [];
            $position = Positions::fromTrades(array_values(array_filter(
                $trades[$broker],
                fn (array $trade) => $trade['date'] <= $on
            )))[$symbol] ?? null;

            return $position === null ? BigDecimal::zero() : BigDecimal::of($position['quantity']);
        };

        foreach ($rows as $row) {
            if ($row['symbol'] === '' || $row['date'] <= $from->toDateString() || $row['date'] > $this->today->toDateString()) {
                continue;
            }

            $date = Carbon::parse($row['date'])->addYearNoOverflow();

            if ($date->lessThanOrEqualTo($this->today) || $date->greaterThan($this->end)) {
                continue;
            }

            $amount = BigDecimal::of($row['amount']);
            $now = $held($row['broker'], $row['symbol'], $this->today->toDateString());

            // No brokerage named, so no holding to scale by: last year's figure as it was.
            if ($now !== null) {
                if (! $now->isPositive()) {
                    continue;
                }

                $then = $held($row['broker'], $row['symbol'], $row['date']);

                if ($then->isPositive() && ! $then->isEqualTo($now)) {
                    $amount = $amount->multipliedBy($now)->dividedBy($then, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
                }
            }

            $this->expected[] = [
                'date' => $date->toDateString(),
                'account_id' => $row['account_id'],
                'broker' => $row['broker'],
                'amount' => $amount,
                'symbol' => $row['symbol'],
                'kind' => 'expected dividend',
                'bucket' => 'dividends',
                'description' => "Dividend {$row['symbol']}, as paid {$row['date']}",
                'paid' => $row['date'],
                'transaction' => $row['id'],
            ];
        }

        // Each dividend on file stands for the one expected nearest it, and for one only: a
        // row declared ahead took two quarters' payments with it when any near one counted.
        foreach ($rows as $row) {
            $window = $windows[$row['account_id'].'|'.$row['symbol']];
            $nearest = null;

            foreach ($this->expected as $i => $dividend) {
                if ($dividend['transaction'] === $row['id']
                    || $dividend['account_id'] !== $row['account_id']
                    || $dividend['symbol'] !== $row['symbol']) {
                    continue;
                }

                $apart = abs(Carbon::parse($row['date'])->diffInDays(Carbon::parse($dividend['date']), false));

                if ($apart <= $window && ($nearest === null || $apart < $nearest[1])) {
                    $nearest = [$i, $apart];
                }
            }

            if ($nearest !== null) {
                unset($this->expected[$nearest[0]]);
            }
        }

        $this->expected = array_values($this->expected);

        usort($this->expected, fn (array $a, array $b) => [$a['date'], $a['symbol']] <=> [$b['date'], $b['symbol']]);
    }

    /**
     * The bonuses expected by the end: every one a cash account was paid in the last year,
     * a year on. A bonus is not a holding, so there is no share count to scale it by and
     * last year's figure stands as it was -- the same assumption a dividend with no
     * brokerage named is placed on.
     *
     * Matched on the description because that is all a bonus is: nothing in the data marks
     * one, and every one of them on file is described exactly so, on the first of a month.
     * A refund or a lone large deposit is left out, because nothing distinguishes the one
     * that recurs from the one that happened, and projecting the second is a guess dressed
     * as a date.
     */
    private function expectedBonuses(): void
    {
        $today = $this->today->toDateString();

        $rows = Transaction::query()
            ->with('meta')
            ->whereIn('account_id', $this->cash->keys())
            ->where('type', TransactionType::Deposit->value)
            ->where('description', 'BONUS')
            ->where('date', '>', $this->today->copy()->subYearNoOverflow()->toDateString())
            ->where('date', '<=', $today)
            ->get()
            ->reject(self::oneOff(...));

        foreach ($rows as $row) {
            $date = Carbon::parse($row->date)->addYearNoOverflow();

            if ($date->lessThanOrEqualTo($this->today) || $date->greaterThan($this->end)) {
                continue;
            }

            $this->expected[] = [
                'date' => $date->toDateString(),
                'account_id' => $row->account_id,
                'amount' => BigDecimal::of((string) $row->amount),
                // No holding, and said so: the near-duplicate pass and the sort both read
                // this, and a symbol would put a bonus in a dividend's de-duplication.
                'symbol' => '',
                'kind' => 'expected bonus',
                'bucket' => 'bonuses',
                'description' => "Bonus, as paid {$row->date}",
                'paid' => $row->date,
                'transaction' => $row->id,
            ];
        }
    }

    /**
     * The double pay expected by the end: a month whose salary came to half again the median
     * month is two months' pay, and the part above the median is placed on the same date a
     * year on. A month's pay in all first, so two payments in one month read as one double
     * rather than as a month and a stray.
     *
     * On the ratio rather than on the month being January, because what identifies it is the
     * size and not the month, and the test has to tell a second payment from a raise. Nine of
     * the last ten Januaries on file came to between 1.7 and 2.0 times the ordinary month,
     * the tenth did not, and the two months on file that were a raise rather than a second
     * payment came to 1.05 and 1.36. Half again sits between those groups with room either
     * side.
     *
     * Read the other way, the cost is a month of salary: a large one-off raise arriving alone
     * in a month clears the test, and puts a whole extra month on the typical line for a year.
     * And a January that is not doubled is a month the projection is a month too rich, which
     * is the price of expecting the nine years that were.
     */
    private function expectedDoublePay(): void
    {
        $rows = Transaction::query()
            ->with('meta')
            ->whereIn('account_id', $this->cash->keys())
            ->where('type', TransactionType::Deposit->value)
            ->where('description', 'SALARY')
            ->where('date', '>', $this->today->copy()->subYearNoOverflow()->toDateString())
            ->where('date', '<=', $this->today->toDateString())
            ->get()
            ->reject(self::oneOff(...));

        if ($rows->isEmpty()) {
            return;
        }

        $byMonth = $rows->groupBy(fn (Transaction $row) => substr($row->date, 0, 7))
            ->map(fn ($inMonth) => [
                'paid' => $inMonth->reduce(
                    fn (BigDecimal $sum, Transaction $row) => $sum->plus(BigDecimal::of((string) $row->amount)),
                    BigDecimal::zero(),
                ),
                'first' => $inMonth->first(),
            ]);

        // The ordinary month, as the median rather than the mean: the doubles are what would
        // drag a mean up, and the months either side of a raise would drag it too.
        $ordinary = self::median($byMonth->pluck('paid')->all());

        foreach ($byMonth as $entry) {
            // Twice the month against three times the ordinary one, so the test is half again
            // without a float anywhere near it.
            if ($entry['paid']->multipliedBy(2)->isLessThan($ordinary->multipliedBy(3))) {
                continue;
            }

            $first = $entry['first'];
            $date = Carbon::parse($first->date)->addYearNoOverflow();

            if ($date->lessThanOrEqualTo($this->today) || $date->greaterThan($this->end)) {
                continue;
            }

            $this->expected[] = [
                'date' => $date->toDateString(),
                'account_id' => $first->account_id,
                'amount' => $entry['paid']->minus($ordinary),
                'symbol' => '',
                'kind' => 'expected double pay',
                'bucket' => 'double_pay',
                'description' => "Double pay, as paid {$first->date}",
                'paid' => $first->date,
                'transaction' => $first->id,
            ];
        }
    }

    /** The dividends expected into one currency's accounts from tomorrow through a day. */
    private function dividendsThrough(string $ccy, string $through): BigDecimal
    {
        return array_reduce(
            array_filter($this->expected, fn (array $d) => $d['date'] <= $through && $this->cash[$d['account_id']]->ccy === $ccy),
            fn (BigDecimal $sum, array $d) => $sum->plus($d['amount']),
            BigDecimal::zero()
        );
    }

    private function add(string $date, int $accountId, string $type, string $amount, string $description, string $kind, array $link): void
    {
        $sign = TransactionType::from($type)->movesBalanceOn(AccountType::Cash);

        if ($sign === 0 || ! $this->cash->has($accountId)) {
            return;
        }

        $this->events[] = [
            'date' => $date,
            'account_id' => $accountId,
            'amount' => $sign > 0 ? BigDecimal::of($amount) : BigDecimal::of($amount)->negated(),
            'description' => $description,
            'kind' => $kind,
            'link' => $link,
        ];
    }

    // ---------------------------------------------------------------------
    // Typical spending
    // ---------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function lastMonths(): array
    {
        return $this->lastMonths ??= CashFlow::lastMonths($this->today, CashFlow::MONTHS + 1);
    }

    /**
     * A section's twelve complete months, this one left out.
     *
     * This month is never a month's worth: on the first it is a day, and as one of twelve it
     * was a month of almost nothing in every median -- on the first of October it took 1,549
     * a month off typical card spending, and read as a month with no income at all.
     *
     * @param  array{months: list<array<string, mixed>>}  $section
     * @return list<array<string, mixed>>
     */
    private static function completeMonths(array $section): array
    {
        return array_slice($section['months'], 0, CashFlow::MONTHS);
    }

    /**
     * A month as the typical figures read it: what was marked one-off taken out of each figure
     * that holds it, so the medians, the averages and the irregular spending are of the rest.
     * The cash flow report keeps it in, being money that moved.
     *
     * @param  array<string, mixed>  $month
     * @return array<string, mixed>
     */
    private static function ordinary(array $month): array
    {
        $less = fn (string $key) => BigDecimal::of($month[$key])->minus($month["one_off_{$key}"] ?? '0');

        $spending = $less('spending');
        $card = $less('card_spending');
        $income = $less('income');
        $dividend = $less('dividend');

        return [
            ...$month,
            'spending' => (string) $spending,
            'card_spending' => (string) $card,
            'cash_spending' => (string) $spending->minus($card),
            'income' => (string) $income,
            'dividend' => (string) $dividend,
            'other_income' => (string) $income->minus($dividend),
            'net' => (string) $income->minus($spending),
        ];
    }

    /** @param  list<array<string, mixed>>  $months */
    private static function monthlyMean(array $months, string $key): BigDecimal
    {
        return array_reduce($months, fn (BigDecimal $sum, array $m) => $sum->plus($m[$key]), BigDecimal::zero())
            ->dividedBy(CashFlow::MONTHS, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
    }

    /**
     * Each card's typical charges a month: its median month of the last twelve, by the day
     * the charges were made, less its active recurring charges, which the forecast already
     * places. Per card rather than per currency, because each card has its own cycle, and
     * a single start for them all left one card's charges in a gap and piled another's
     * into the month after.
     *
     * @return list<array{account: Account, monthly: BigDecimal}>
     */
    private function cardTypical(): array
    {
        if ($this->cardTypical !== null) {
            return $this->cardTypical;
        }

        // The twelve complete months, as the cash median takes: see completeMonths().
        $from = $this->today->copy()->startOfMonth()->subMonthsNoOverflow(CashFlow::MONTHS)->toDateString();
        $through = $this->today->copy()->startOfMonth()->subDay()->toDateString();
        $cards = Account::query()->where('type', AccountType::Card->value)->with('meta')->get();

        // Plain rows and their bags, as CashFlow::facts() reads its year: a model each was
        // a third of a second for two keys.
        $charged = DB::table('transactions')
            ->whereIn('account_id', $cards->pluck('id'))
            ->where('type', TransactionType::Charge->value)
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->whereBetween('date', [$from, $through])
            ->get(['id', 'account_id', 'amount', 'date']);
        $bags = CashFlow::bagsOf($charged->pluck('id')->all());
        $charged = $charged->groupBy('account_id');

        $rules = RecurringTransaction::query()
            ->where('active', true)
            ->where('type', TransactionType::Charge->value)
            ->whereIn('account_id', $cards->pluck('id'))
            ->get()
            ->filter(fn (RecurringTransaction $rule) => $rule->end_date === null || $rule->end_date >= $this->today->toDateString())
            ->groupBy('account_id');

        $typical = [];

        $months = [];

        for ($month = Carbon::parse($from); $month->toDateString() <= $through; $month->addMonthNoOverflow()) {
            $months[] = $month->format('Y-m');
        }

        foreach ($cards as $card) {
            // card_amount, the figure the card owes, as the statements and CashFlow read it,
            // summed by the month each was charged in, a month with none as nothing.
            $byMonth = array_fill_keys($months, BigDecimal::zero());

            foreach ($charged[$card->id] ?? [] as $row) {
                $bag = $bags[$row->id] ?? [];

                if ($bag['one_off'] ?? false) {
                    continue;
                }

                $month = substr((string) $row->date, 0, 7);

                if (isset($byMonth[$month])) {
                    $byMonth[$month] = $byMonth[$month]->plus((string) ($bag['card_amount'] ?? $row->amount));
                }
            }

            $covered = ($rules[$card->id] ?? collect())->reduce(function (BigDecimal $sum, RecurringTransaction $rule) {
                $amount = BigDecimal::of((string) ($rule->card_amount ?? $rule->amount));

                return $sum->plus(Frequency::from($rule->frequency) === Frequency::Yearly
                    ? $amount->dividedBy(12, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp)
                    : $amount);
            }, BigDecimal::zero());

            $monthly = self::median(array_values($byMonth))->minus($covered);

            if ($monthly->isPositive()) {
                $typical[] = ['account' => $card, 'monthly' => $monthly];
            }
        }

        return $this->cardTypical = $typical;
    }

    /**
     * Per currency: the last twelve months' median month of spending, less the monthly share
     * of the active rules that spend, so rent recorded by a rule is not counted twice. Never
     * below zero.
     *
     * Split into cash and card, because they reach the bank at different times: cash the
     * day it is spent, a card's when its statement is paid. The card half is cardTypical()'s,
     * card by card, which projection() puts on each card's own due dates.
     *
     * Income the same way: the year's average less what the recurring rules that pay in
     * already bring, so a forecast that knows only the salary does not read every other
     * deposit of the year as never happening again.
     *
     * @return array<string, array{monthly: string, cash: string, card: string, irregular: string, average: string, recurring: string, income: string, income_average: string, income_dividends: string, income_recurring: string}>
     */
    private function typicalSpending(): array
    {
        $recurring = [];
        $cardRecurring = [];
        $earning = [];
        $earningRules = [];

        $rules = RecurringTransaction::query()->where('active', true)->with('account')->get();

        foreach ($rules as $rule) {
            $spends = ($rule->account?->type === AccountType::Cash->value && $rule->type === TransactionType::Withdraw->value)
                || ($rule->account?->type === AccountType::Card->value && $rule->type === TransactionType::Charge->value);

            $earns = $rule->account?->type === AccountType::Cash->value
                && TransactionType::from($rule->type)->movesBalanceOn(AccountType::Cash) > 0;

            if ((! $spends && ! $earns) || ($rule->end_date !== null && $rule->end_date < $this->today->toDateString())) {
                continue;
            }

            $amount = BigDecimal::of((string) ($rule->card_amount ?? $rule->amount));
            $monthly = Frequency::from($rule->frequency) === Frequency::Yearly
                ? $amount->dividedBy(12, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp)
                : $amount;

            if ($earns) {
                $earning[$rule->account->ccy] = ($earning[$rule->account->ccy] ?? BigDecimal::zero())->plus($monthly);
                $earningRules[] = ['rule' => $rule, 'monthly' => $monthly];

                continue;
            }

            $ccy = $rule->account->ccy;
            $recurring[$ccy] = ($recurring[$ccy] ?? BigDecimal::zero())->plus($monthly);

            if ($rule->account->type === AccountType::Card->value) {
                $cardRecurring[$ccy] = ($cardRecurring[$ccy] ?? BigDecimal::zero())->plus($monthly);
            }
        }

        $typical = [];
        $cards = [];

        foreach ($this->cardTypical() as $card) {
            $ccy = $card['account']->ccy;
            $cards[$ccy] = ($cards[$ccy] ?? BigDecimal::zero())->plus($card['monthly']);
        }

        $brought = $this->broughtByRules($earningRules);

        foreach ($this->lastMonths() as $section) {
            $months = array_map(self::ordinary(...), self::completeMonths($section));

            // The median month, not the mean: one month of a holiday's charges set the mean,
            // and with it every month the forecast looked ahead at, a fifth above an ordinary
            // one. The mean stays as the figure shown for comparison.
            $cashMedian = self::median(array_map(
                fn (array $month) => BigDecimal::of($month['cash_spending']),
                $months
            ));

            $average = self::monthlyMean($months, 'spending');
            $covered = $recurring[$section['ccy']] ?? BigDecimal::zero();
            $cardCovered = $cardRecurring[$section['ccy']] ?? BigDecimal::zero();

            $floor = fn (BigDecimal $value) => $value->isNegative() ? BigDecimal::zero() : $value;
            $card = $cards[$section['ccy']] ?? BigDecimal::zero();
            $cash = $floor($cashMedian->minus($covered->minus($cardCovered)));

            // Irregular spending: what the year spent beyond an ordinary month, its average less
            // the ordinary month and the rules. A median sets the annual bill and the odd
            // appliance at nothing, and they are not nothing; with this the ordinary month, the
            // rules and it come to the year's average. What was marked one-off is in neither the
            // average nor the median (see ordinary()), because it is not expected again: left in,
            // a 76,305 purchase was charged to every month ahead at 6,360 a month.
            $irregular = $floor($average->minus($cash)->minus($card)->minus($covered));

            $incomeAverage = self::monthlyMean($months, 'income');
            $dividendAverage = self::monthlyMean($months, 'dividend');
            $earned = $earning[$section['ccy']] ?? BigDecimal::zero();

            // The median month, not the mean, for the same reason spending takes one: a
            // bonus, a month's double pay and a tax refund all fell inside the year, and
            // the mean carried all three into every month the forecast looks ahead at --
            // eleven times over for a payment that arrives once. The mean is kept below as
            // the figure shown for comparison.
            //
            // What is left of each month is what its rules did not bring that month: see
            // broughtByRules(). Today's rule amount taken off every month instead read a raise
            // as income lost -- nine months of a salary paid at the old figure each came out
            // 2,116 short, four of them below nothing, and the median with them.
            $incomeMedian = self::median(array_map(
                fn (array $month) => BigDecimal::of($month['other_income'])
                    ->minus($brought[$section['ccy']][$month['month']] ?? BigDecimal::zero()),
                $months,
            ));

            $typical[$section['ccy']] = [
                'monthly' => self::money($cash->plus($card)),
                'cash' => self::money($cash),
                'card' => self::money($card),
                'irregular' => self::money($irregular),
                'average' => self::money($average),
                'recurring' => self::money($covered),
                // Income the rules do not bring: the small deposits and the interest, which
                // arrive every month. Dividends are aside, as expectedDividends() places
                // them, and a bonus is placed on its date by expectedBonuses().
                'income' => self::money($floor($incomeMedian)),
                'income_average' => self::money($incomeAverage),
                'income_dividends' => self::money($dividendAverage),
                'income_median' => self::money($incomeMedian),
                'income_recurring' => self::money($earned),
            ];
        }

        return $typical;
    }

    /**
     * What the earning rules brought in each of the twelve complete months, per currency and
     * month, for typical income to be what is left.
     *
     * A rule that has written rows in the window is taken at what those rows paid, month by
     * month: the recorder writes an occurrence with the rule's account, type and description,
     * the match the Recurring page finds a rule's rows by. So a raise, a month paid twice and
     * a back payment are the rule's and not other income -- the double pay is placed on its
     * own date by expectedDoublePay(), and counted here as well it would be counted twice.
     *
     * A rule with no rows in the window is taken at its amount every month, as before: it was
     * made after the money it records began, which arrived as some other deposit, and without
     * this that deposit stays in typical income while the rule adds it to the known line.
     *
     * Rules alike in all three share their rows, which are taken once, not once a rule.
     *
     * @param  list<array{rule: RecurringTransaction, monthly: BigDecimal}>  $rules
     * @return array<string, array<string, BigDecimal>>
     */
    private function broughtByRules(array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $from = $this->today->copy()->startOfMonth()->subMonthsNoOverflow(CashFlow::MONTHS);
        $through = $this->today->copy()->startOfMonth()->subDay();

        $months = [];

        for ($month = $from->copy(); $month->lessThanOrEqualTo($through); $month->addMonthNoOverflow()) {
            $months[] = $month->format('Y-m');
        }

        $key = fn ($accountId, string $type, string $description) => "{$accountId}|{$type}|{$description}";

        $rows = Transaction::query()
            ->whereIn('account_id', collect($rules)->pluck('rule.account_id')->unique())
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->whereBetween('date', [$from->toDateString(), $through->toDateString()])
            ->get(['account_id', 'type', 'description', 'date', 'amount'])
            ->groupBy(fn (Transaction $row) => $key($row->account_id, $row->type, (string) $row->description));

        $brought = [];

        foreach (collect($rules)->groupBy(fn (array $r) => $key($r['rule']->account_id, $r['rule']->type, (string) $r['rule']->description)) as $match => $alike) {
            $ccy = $alike->first()['rule']->account->ccy;
            $own = $rows->get($match);

            foreach ($months as $month) {
                $amount = $own === null
                    ? $alike->reduce(fn (BigDecimal $sum, array $r) => $sum->plus($r['monthly']), BigDecimal::zero())
                    : $own->filter(fn (Transaction $row) => str_starts_with((string) $row->date, $month))
                        ->reduce(fn (BigDecimal $sum, Transaction $row) => $sum->plus((string) $row->amount), BigDecimal::zero());

                $brought[$ccy][$month] = ($brought[$ccy][$month] ?? BigDecimal::zero())->plus($amount);
            }
        }

        return $brought;
    }

    /**
     * Whether a row was marked as money that will not come or go again. Such a row is not
     * projected a year on: a bonus, a double month or a dividend marked so is last year's
     * alone.
     */
    private static function oneOff(Transaction $row): bool
    {
        return (bool) ($row->meta?->meta['one_off'] ?? false);
    }

    /**
     * The middle of a list of figures, or the halfway point of the middle two. Nothing is
     * zero.
     *
     * @param  list<BigDecimal>  $values
     */
    private static function median(array $values): BigDecimal
    {
        if ($values === []) {
            return BigDecimal::zero();
        }

        usort($values, fn (BigDecimal $a, BigDecimal $b) => $a->compareTo($b));
        $middle = intdiv(count($values), 2);

        return count($values) % 2
            ? $values[$middle]
            : $values[$middle - 1]->plus($values[$middle])->dividedBy(2, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
    }

    private static function money(BigDecimal $value): string
    {
        return (string) $value->toScale(TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
    }
}
