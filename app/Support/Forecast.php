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
 * Nothing here is guessed except "typical spending", which is kept apart: a per-currency
 * daily allowance from the last year's spending, less what the recurring rules already
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

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private Carbon $today, private Carbon $end)
    {
        $this->cash = Account::query()
            ->where('type', AccountType::Cash->value)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

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
    public function projection(): array
    {
        $opening = AccountBalance::forAccounts($this->cash, $this->today->toDateString());
        $typical = $this->typicalSpending();
        $report = [];

        foreach ($this->cash->groupBy('ccy') as $ccy => $accounts) {
            $balances = [];

            foreach ($accounts as $account) {
                $balances[$account->id] = BigDecimal::of($opening[$account->id] ?? '0');
            }

            // An account with nothing in it and nothing coming is left off the page.
            $moving = collect($this->events)->pluck('account_id')->unique()->all();
            $balances = array_filter(
                $balances,
                fn (BigDecimal $balance, int $id) => ! $balance->isZero() || in_array($id, $moving, true),
                ARRAY_FILTER_USE_BOTH
            );

            if ($balances === []) {
                continue;
            }

            $lowest = array_map(fn (BigDecimal $balance) => [$balance, $this->today->toDateString()], $balances);
            $daily = BigDecimal::of($typical[$ccy]['monthly'] ?? '0')
                ->multipliedBy(12)
                ->dividedBy(365, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);

            $byDay = collect($this->events)
                ->whereIn('account_id', array_keys($balances))
                ->groupBy('date');

            $points = [];
            $allowance = BigDecimal::zero();
            $lowestTotal = null;

            for ($day = $this->today->copy(); $day->lessThanOrEqualTo($this->end); $day->addDay()) {
                $date = $day->toDateString();

                foreach ($byDay[$date] ?? [] as $event) {
                    $balances[$event['account_id']] = $balances[$event['account_id']]->plus($event['amount']);
                }

                // From tomorrow: today's spending is in today's balance already.
                if ($day->greaterThan($this->today)) {
                    $allowance = $allowance->plus($daily);
                }

                foreach ($balances as $id => $balance) {
                    if ($balance->isLessThan($lowest[$id][0])) {
                        $lowest[$id] = [$balance, $date];
                    }
                }

                $total = array_reduce($balances, fn (BigDecimal $sum, BigDecimal $b) => $sum->plus($b), BigDecimal::zero());

                if ($lowestTotal === null || $total->isLessThan($lowestTotal[0])) {
                    $lowestTotal = [$total, $date];
                }

                $points[] = [
                    'date' => $date,
                    'known' => self::money($total),
                    'typical' => self::money($total->minus($allowance)),
                ];
            }

            $report[] = [
                'ccy' => $ccy,
                'points' => $points,
                'typical_monthly' => $typical[$ccy]['monthly'] ?? '0.0000',
                'typical_basis' => $typical[$ccy] ?? null,
                'lowest' => ['amount' => self::money($lowestTotal[0]), 'date' => $lowestTotal[1]],
                'accounts' => collect($balances)->map(fn (BigDecimal $closing, int $id) => [
                    'id' => $id,
                    'name' => $this->cash[$id]->name,
                    'opening' => self::money(BigDecimal::of($opening[$id] ?? '0')),
                    'closing' => self::money($closing),
                    'lowest' => ['amount' => self::money($lowest[$id][0]), 'date' => $lowest[$id][1]],
                ])->values()->all(),
            ];
        }

        return $report;
    }

    /**
     * The known movements in the next UPCOMING_DAYS days, earliest first.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(): array
    {
        $until = $this->today->copy()->addDays(self::UPCOMING_DAYS)->toDateString();

        return collect($this->events)
            ->filter(fn (array $event) => $event['date'] <= $until)
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
    public function monthOutlook(): array
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
        $months = collect(CashFlow::lastMonths($this->today))->keyBy('ccy');

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

        return $report;
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

    /**
     * The known changes to net worth ahead, per currency: every cash movement except a
     * card statement's payment, whose debt net worth already carries, and every pending
     * card charge, which it does not carry yet.
     *
     * @return list<array{date: string, ccy: string, amount: BigDecimal}>
     */
    public function netWorthEvents(): array
    {
        $events = [];

        foreach ($this->events as $event) {
            if ($event['kind'] !== 'statement') {
                $events[] = ['date' => $event['date'], 'ccy' => $this->cash[$event['account_id']]->ccy, 'amount' => $event['amount']];
            }
        }

        $pending = Transaction::query()
            ->with(['account', 'meta'])
            ->where('status', TransactionStatus::Pending->value)
            ->where('type', TransactionType::Charge->value)
            ->where('date', '<=', $this->end->toDateString())
            ->get();

        foreach ($pending as $row) {
            $events[] = [
                'date' => max($row->date, $this->today->toDateString()),
                'ccy' => $row->account->ccy,
                'amount' => BigDecimal::of($row->meta?->meta['card_amount'] ?? $row->amount)->negated(),
            ];
        }

        return $events;
    }

    /**
     * Typical spending a day, per currency, as the projection lines take it off.
     *
     * @return array<string, BigDecimal>
     */
    public function typicalDaily(): array
    {
        return array_map(
            fn (array $typical) => BigDecimal::of($typical['monthly'])
                ->multipliedBy(12)
                ->dividedBy(365, TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp),
            $this->typicalSpending()
        );
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
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

                $pending = CardStatement::rowsInPeriod($card, $statement->dueDate)
                    ->filter(fn (Transaction $row) => $row->status === TransactionStatus::Pending->value
                        && $row->type === TransactionType::Charge->value)
                    ->reduce(fn (BigDecimal $sum, Transaction $row) => $sum->plus(
                        $row->meta?->meta['card_amount'] ?? $row->amount
                    ), BigDecimal::zero());

                $owed = BigDecimal::of($statement->owed())->plus($pending);

                if (! $owed->isPositive()) {
                    continue;
                }

                if ($bank === null || ! $this->cash->has($bank->id)) {
                    $this->warnings[] = sprintf(
                        'Card [%s] owes %s %s due %s but names no bank to pay it from, so it is not in any balance here.',
                        $card->name,
                        self::money($owed),
                        $card->ccy,
                        $statement->dueDate
                    );

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

        foreach (CashFlow::lastMonths($this->today) as $section) {
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
