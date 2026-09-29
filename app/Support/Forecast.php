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
 * Nothing here is guessed except "typical spending", which is kept apart: a daily allowance from the last year's spending, less what the recurring rules already
 * account for. The known figures never include it, so the page can show both.
 *
 * Dividends are left out: they are irregular, and money not yet declared is not money
 * coming.
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
        $opening = AccountBalance::forAccounts($this->cash, $day);
        $typical = $this->typicalSpending();
        $moving = collect($this->events)->pluck('account_id')->unique()->all();

        // Each account at today's rate into the base currency, the only rate known for the
        // days ahead. One with no rate yet is left out and named, as on the net worth page.
        $rates = [];
        $balances = [];

        foreach ($this->cash as $account) {
            $balance = BigDecimal::of($opening[$account->id] ?? '0');

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

        $monthly = $average = $covered = BigDecimal::zero();

        foreach ($typical as $ccy => $figures) {
            if ($only !== null && $ccy !== $only) {
                continue;
            }

            $rate = $only === null ? $this->fx->rate($ccy, $day) : '1';

            if ($rate === null) {
                continue;
            }

            $monthly = $monthly->plus(BigDecimal::of($figures['monthly'])->multipliedBy($rate));
            $average = $average->plus(BigDecimal::of($figures['average'])->multipliedBy($rate));
            $covered = $covered->plus(BigDecimal::of($figures['recurring'])->multipliedBy($rate));
        }

        $daily = $monthly->multipliedBy(12)->dividedBy(365, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);

        $lowest = array_map(fn (BigDecimal $balance) => [$balance, $day], $balances);
        $byDay = collect($this->events)->whereIn('account_id', array_keys($balances))->groupBy('date');

        $points = [];
        $allowance = BigDecimal::zero();
        $lowestTotal = null;

        for ($cursor = $this->today->copy(); $cursor->lessThanOrEqualTo($this->end); $cursor->addDay()) {
            $date = $cursor->toDateString();

            foreach ($byDay[$date] ?? [] as $event) {
                $balances[$event['account_id']] = $balances[$event['account_id']]->plus($event['amount']);
            }

            // From tomorrow: today's spending is in today's balance already.
            if ($cursor->greaterThan($this->today)) {
                $allowance = $allowance->plus($daily);
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

            $points[] = [
                'date' => $date,
                'known' => self::money($total),
                'typical' => self::money($total->minus($allowance)),
            ];
        }

        return [[
            'ccy' => $shownIn,
            'points' => $points,
            'typical_monthly' => self::money($monthly),
            'typical_basis' => ['average' => self::money($average), 'recurring' => self::money($covered)],
            'lowest' => ['amount' => self::money($lowestTotal[0]), 'date' => $lowestTotal[1]],
            'accounts' => collect($balances)->map(function (BigDecimal $closing, int $id) use ($opening, $lowest, $base) {
                $start = BigDecimal::of($opening[$id] ?? '0');

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
            $typicalRest = BigDecimal::of($typical[$ccy]['monthly'] ?? '0')
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
                'likely_known' => self::money($known),
                'likely_net' => self::money($known->minus($typicalRest)),
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
        $paths = ['so_far.income', 'so_far.spending', 'so_far.net', 'to_come.income', 'to_come.spending', 'typical_rest', 'likely_known', 'likely_net', 'average_net'];
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

        foreach ($cards as $card) {
            $bank = $card->settlementAccount();

            foreach (CardStatement::forAccount($card) as $statement) {
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
     * Per currency: the last twelve months' average spending, less the monthly share of
     * the active rules that spend, so rent recorded by a rule is not counted twice. Never
     * below zero.
     *
     * @return array<string, array{monthly: string, average: string, recurring: string}>
     */
    private function typicalSpending(): array
    {
        $recurring = [];

        $rules = RecurringTransaction::query()->where('active', true)->with('account')->get();

        foreach ($rules as $rule) {
            $spends = ($rule->account?->type === AccountType::Cash->value && $rule->type === TransactionType::Withdraw->value)
                || ($rule->account?->type === AccountType::Card->value && $rule->type === TransactionType::Charge->value);

            if (! $spends || ($rule->end_date !== null && $rule->end_date < $this->today->toDateString())) {
                continue;
            }

            $amount = BigDecimal::of((string) ($rule->card_amount ?? $rule->amount));
            $monthly = Frequency::from($rule->frequency) === Frequency::Yearly
                ? $amount->dividedBy(12, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp)
                : $amount;

            $ccy = $rule->account->ccy;
            $recurring[$ccy] = ($recurring[$ccy] ?? BigDecimal::zero())->plus($monthly);
        }

        $typical = [];

        foreach ($this->lastMonths() as $section) {
            $average = BigDecimal::of($section['totals']['spending'])
                ->dividedBy(CashFlow::MONTHS, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
            $covered = $recurring[$section['ccy']] ?? BigDecimal::zero();
            $monthly = $average->minus($covered);

            $typical[$section['ccy']] = [
                'monthly' => self::money($monthly->isNegative() ? BigDecimal::zero() : $monthly),
                'average' => self::money($average),
                'recurring' => self::money($covered),
            ];
        }

        return $typical;
    }

    private static function money(BigDecimal $value): string
    {
        return (string) $value->toScale(TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
    }
}
