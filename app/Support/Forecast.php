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
 * declared is not money coming. Typical income's average has them, as an estimate may.
 */
class Forecast
{
    /** The horizons offered, in months. */
    public const HORIZONS = [3, 6, 12];

    /** How far ahead the upcoming list reaches, in days. */
    public const UPCOMING_DAYS = 30;

    /** @var Collection<int, Account> */
    private Collection $cash;

    /** @var list<array{date: string, account_id: int, amount: BigDecimal, description: string, kind: string, link: array<string, mixed>}> */
    private array $events = [];

    /** @var list<array{ccy: string, message: string}> */
    private array $warnings = [];

    /**
     * The last twelve months' cash flow, read once.
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

    private Fx $fx;

    public function __construct(private Carbon $today, private Carbon $end)
    {
        $this->cash = Account::query()
            ->where('type', AccountType::Cash->value)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $this->fx = Fx::for(Account::query()->distinct()->pluck('ccy')->all());

        $this->knownRows();
        $this->recurring();
        $this->statements();

        usort($this->events, fn (array $a, array $b) => [$a['date'], $a['description']] <=> [$b['date'], $b['description']]);
    }

    public static function for(Carbon $today, int $months): self
    {
        return new self($today->copy()->startOfDay(), $today->copy()->startOfDay()->addMonthsNoOverflow($months));
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

        $monthly = $average = $covered = $cashMonthly = $cardMonthly = BigDecimal::zero();
        $incomeMonthly = $incomeAverage = $incomeCovered = BigDecimal::zero();

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
            $incomeMonthly = $incomeMonthly->plus(BigDecimal::of($figures['income'])->multipliedBy($rate));
            $incomeAverage = $incomeAverage->plus(BigDecimal::of($figures['income_average'])->multipliedBy($rate));
            $incomeCovered = $incomeCovered->plus(BigDecimal::of($figures['income_recurring'])->multipliedBy($rate));
            $average = $average->plus(BigDecimal::of($figures['average'])->multipliedBy($rate));
            $covered = $covered->plus(BigDecimal::of($figures['recurring'])->multipliedBy($rate));
        }

        $perDay = fn (BigDecimal $month) => $month->multipliedBy(12)->dividedBy(365, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
        $dailyCash = $perDay($cashMonthly);
        $dailyIncome = $perDay($incomeMonthly);

        $lowest = array_map(fn (BigDecimal $balance) => [$balance, $day], $balances);
        $byDay = collect($this->events)->whereIn('account_id', array_keys($balances))->groupBy('date');

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
        $earned = BigDecimal::zero();
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
            $monthsAhead[$month] ??= ['month' => $month, 'in' => $zero, 'out' => $zero, 'typical' => $zero, 'typical_in' => $zero];

            // From tomorrow: today's spending is in today's balance already.
            if ($cursor->greaterThan($this->today)) {
                $daily = $dailyCash->plus($cardDue[$date] ?? BigDecimal::zero());
                $allowance = $allowance->plus($daily);
                $earned = $earned->plus($dailyIncome);
                $monthsAhead[$month]['typical'] = $monthsAhead[$month]['typical']->plus($daily);
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
                    'with_typical' => self::money($running->minus($allowance)->plus($earned)),
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
                foreach (['known' => $total, 'typical' => $total->minus($allowance)->plus($earned)] as $line => $value) {
                    if ($lowestAhead[$line] === null || $value->isLessThan($lowestAhead[$line][0])) {
                        $lowestAhead[$line] = [$value, $date];
                    }
                }
            }

            $monthsAhead[$month]['end_known'] = $total;
            $monthsAhead[$month]['end_typical'] = $total->minus($allowance)->plus($earned);

            $points[] = [
                'date' => $date,
                'known' => self::money($total),
                'typical' => self::money($total->minus($allowance)->plus($earned)),
                // Running totals, so the page's what-if can redraw without the server.
                'allowance' => self::money($allowance),
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
            'typical_income_basis' => ['average' => self::money($incomeAverage), 'recurring' => self::money($incomeCovered)],
            'typical_basis' => [
                'average' => self::money($average),
                'recurring' => self::money($covered),
                'cash' => self::money($cashMonthly),
                'card' => self::money($cardMonthly),
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
                'typical_in' => self::money($m['typical_in']),
                'net_known' => self::money($m['in']->minus($m['out'])),
                'net_typical' => self::money($m['in']->minus($m['out'])->minus($m['typical'])->plus($m['typical_in'])),
                'end_known' => self::money($m['end_known']),
                'end_typical' => self::money($m['end_typical']),
            ], $monthsAhead)),
            'events' => $events,
            'accounts' => collect($balances)->map(function (BigDecimal $closing, int $id) use ($openingBalances, $lowest, $base) {
                $start = BigDecimal::of($openingBalances[$id] ?? '0');

                return [
                    'id' => $id,
                    'name' => $this->cash[$id]->name,
                    'ccy' => $this->cash[$id]->ccy,
                    'opening' => self::money($base($id, $start)),
                    'closing' => self::money($base($id, $closing)),
                    'lowest' => ['amount' => self::money($base($id, $lowest[$id][0])), 'date' => $lowest[$id][1]],
                    // In the account's own currency, for the one not held in the base.
                    'native' => [
                        'opening' => self::money($start),
                        'closing' => self::money($closing),
                        'lowest' => self::money($lowest[$id][0]),
                    ],
                ];
            })->values()->all(),
        ]];
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

        return collect($this->events)
            ->filter(fn (array $event) => $event['date'] <= $until)
            ->filter(fn (array $event) => $only === null || $this->cash[$event['account_id']]->ccy === $only)
            ->map(fn (array $event) => [
                ...$event,
                'amount' => self::money($event['amount']),
                'account' => $this->cash[$event['account_id']]->name,
                'ccy' => $this->cash[$event['account_id']]->ccy,
            ])
            ->values()
            ->all();
    }

    /**
     * Per currency: this month so far, what is known still to come before it ends, typical
     * spending for the days left, and the likely net at month end beside the year's average.
     *
     * So far is CashFlow's month, which already counts a posted row dated later this month;
     * to come is what it cannot see yet, pending rows and occurrences not written. A card
     * charge is spending on its own date, and a statement payment is not spending at all,
     * as in the cash flow report.
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
            ->whereHas('account', fn ($q) => $q->whereIn('type', [AccountType::Cash->value, AccountType::Card->value]))
            ->get();

        foreach ($pending as $row) {
            $this->classifyToCome($row->account, $row->type, (string) ($row->meta?->meta['card_amount'] ?? $row->amount), $bump);
        }

        foreach (RecurringTransaction::query()->where('active', true)->with('account')->get() as $rule) {
            foreach ($rule->dueThrough($this->today->copy()->endOfMonth()) as $date) {
                $this->classifyToCome($rule->account, $rule->type, (string) ($rule->card_amount ?? $rule->amount), $bump);
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
            $typicalRest = BigDecimal::of($typical[$ccy]['cash'] ?? '0')
                ->multipliedBy($daysLeft)
                ->dividedBy($daysInMonth, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);

            $typicalIncomeRest = BigDecimal::of($typical[$ccy]['income'] ?? '0')
                ->multipliedBy($daysLeft)
                ->dividedBy($daysInMonth, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);

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
                'average_net' => self::money(BigDecimal::of($section['totals']['net'])->dividedBy(CashFlow::MONTHS, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp)),
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

    /** Money in or spent, as the cash flow report reads it; anything else is neither. */
    private function classifyToCome(?Account $account, string $type, string $amount, callable $bump): void
    {
        if ($account === null) {
            return;
        }

        $sign = TransactionType::from($type)->movesBalanceOn(AccountType::from($account->type));

        if ($account->type === AccountType::Card->value) {
            if ($sign < 0) {
                $bump($account->ccy, 'spending', BigDecimal::of($amount));
            }

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

    /** Rows on a cash account dated after today, and pending ones, which post today at the earliest. */
    private function knownRows(): void
    {
        $rows = Transaction::query()
            ->whereIn('account_id', $this->cash->keys())
            ->where('date', '<=', $this->end->toDateString())
            ->where(fn ($q) => $q
                ->where('date', '>', $this->today->toDateString())
                ->orWhere('status', TransactionStatus::Pending->value))
            ->get();

        foreach ($rows as $row) {
            $this->add(
                max($row->date, $this->today->toDateString()),
                $row->account_id,
                $row->type,
                (string) $row->amount,
                $row->description,
                $row->status === TransactionStatus::Pending->value ? 'pending' : 'scheduled',
                ['transaction' => $row->id]
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
                    $bank = $rule->account->settlementAccount();

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
        return $this->lastMonths ??= CashFlow::lastMonths($this->today);
    }

    /**
     * Each card's typical charges a month: its own charges over the last twelve months, by
     * the day they were made, less its active recurring charges, which the forecast already
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

        $from = $this->today->copy()->startOfMonth()->subMonthsNoOverflow(CashFlow::MONTHS - 1)->toDateString();
        $cards = Account::query()->where('type', AccountType::Card->value)->with('meta')->get();

        $charged = Transaction::query()
            ->with('meta')
            ->whereIn('account_id', $cards->pluck('id'))
            ->where('type', TransactionType::Charge->value)
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->whereBetween('date', [$from, $this->today->copy()->endOfMonth()->toDateString()])
            ->get()
            ->groupBy('account_id');

        $rules = RecurringTransaction::query()
            ->where('active', true)
            ->where('type', TransactionType::Charge->value)
            ->whereIn('account_id', $cards->pluck('id'))
            ->get()
            ->filter(fn (RecurringTransaction $rule) => $rule->end_date === null || $rule->end_date >= $this->today->toDateString())
            ->groupBy('account_id');

        $typical = [];

        foreach ($cards as $card) {
            // card_amount, the figure the card owes, as the statements and CashFlow read it.
            $total = ($charged[$card->id] ?? collect())->reduce(
                fn (BigDecimal $sum, Transaction $row) => $sum->plus((string) ($row->meta?->meta['card_amount'] ?? $row->amount)),
                BigDecimal::zero()
            );
            $covered = ($rules[$card->id] ?? collect())->reduce(function (BigDecimal $sum, RecurringTransaction $rule) {
                $amount = BigDecimal::of((string) ($rule->card_amount ?? $rule->amount));

                return $sum->plus(Frequency::from($rule->frequency) === Frequency::Yearly
                    ? $amount->dividedBy(12, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp)
                    : $amount);
            }, BigDecimal::zero());

            $monthly = $total->dividedBy(CashFlow::MONTHS, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp)->minus($covered);

            if ($monthly->isPositive()) {
                $typical[] = ['account' => $card, 'monthly' => $monthly];
            }
        }

        return $this->cardTypical = $typical;
    }

    /**
     * Per currency: the last twelve months' average spending, less the monthly share of
     * the active rules that spend, so rent recorded by a rule is not counted twice. Never
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
     * @return array<string, array{monthly: string, cash: string, card: string, average: string, recurring: string, income: string, income_average: string, income_recurring: string}>
     */
    private function typicalSpending(): array
    {
        $recurring = [];
        $cardRecurring = [];
        $earning = [];

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

        foreach ($this->lastMonths() as $section) {
            $average = BigDecimal::of($section['totals']['spending'])
                ->dividedBy(CashFlow::MONTHS, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
            $cardAverage = BigDecimal::of($section['totals']['card_spending'])
                ->dividedBy(CashFlow::MONTHS, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
            $covered = $recurring[$section['ccy']] ?? BigDecimal::zero();
            $cardCovered = $cardRecurring[$section['ccy']] ?? BigDecimal::zero();

            $floor = fn (BigDecimal $value) => $value->isNegative() ? BigDecimal::zero() : $value;
            $card = $cards[$section['ccy']] ?? BigDecimal::zero();
            $cash = $floor($average->minus($cardAverage)->minus($covered->minus($cardCovered)));

            $incomeAverage = BigDecimal::of($section['totals']['income'])
                ->dividedBy(CashFlow::MONTHS, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
            $earned = $earning[$section['ccy']] ?? BigDecimal::zero();

            $typical[$section['ccy']] = [
                'monthly' => self::money($cash->plus($card)),
                'cash' => self::money($cash),
                'card' => self::money($card),
                'average' => self::money($average),
                'recurring' => self::money($covered),
                // Income the rules do not bring: bonuses, refunds, dividends, the odd deposit.
                'income' => self::money($floor($incomeAverage->minus($earned))),
                'income_average' => self::money($incomeAverage),
                'income_recurring' => self::money($earned),
            ];
        }

        return $typical;
    }

    private static function money(BigDecimal $value): string
    {
        return (string) $value->toScale(TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
    }
}
