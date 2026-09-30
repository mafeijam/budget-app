<?php

namespace App\Support;

use App\DTO\TransactionMetaData;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Income, spending and money invested, per currency and month.
 *
 * Money moved between these accounts is neither. A card repayment's bank withdrawal is
 * left out, or every purchase would count twice -- once charged, once paid -- and a
 * trade's cash side is invested rather than spent. Both are told apart by the row the
 * bank row is paired with, so nothing has to be tagged by hand.
 *
 * A charge counts on its statement's due date, when the bank pays for it, or with
 * $onDueDate false on the day it was made. The due date is the default because it puts
 * card spending on the same clock as cash spending, the month the money left the bank.
 * Either way it is in the card's currency at its card_amount, the figure AccountBalance
 * sums. lastMonths()
 * reports each currency in its own money; combined() converts every row into the base
 * currency at its own day's rate.
 */
class CashFlow
{
    public const MONTHS = 12;

    /**
     * @return list<array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}>
     */
    public static function lastMonths(Carbon $today, int $count = self::MONTHS, bool $onDueDate = true): array
    {
        return self::byCurrency(self::facts($today, $count, $onDueDate), $today, $count);
    }

    /**
     * Every currency in one report in the base currency, and the currencies left out for
     * having no rate on a row's day, so a total missing something says so.
     *
     * @return array{report: array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}|null, unconverted: list<string>}
     */
    public static function combined(Carbon $today, int $count = self::MONTHS, bool $onDueDate = true): array
    {
        return self::inBase(self::facts($today, $count, $onDueDate), $today, $count);
    }

    /**
     * Both reports, and the currencies the base one left out, off one reading of the rows.
     *
     * The page shows the currencies side by side when one is picked and the combined one
     * when none is, but it needs the list of currencies either way -- so it needs both
     * readings always, and asking for them one at a time read a year of transactions twice.
     *
     * @return array{currencies: list<string>, by_currency: list<array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}>, combined: array{report: array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}|null, unconverted: list<string>}}
     */
    public static function both(Carbon $today, int $count = self::MONTHS, bool $onDueDate = true): array
    {
        $facts = self::facts($today, $count, $onDueDate);

        return [
            'currencies' => array_column($byCurrency = self::byCurrency($facts, $today, $count), 'ccy'),
            'by_currency' => $byCurrency,
            'combined' => self::inBase($facts, $today, $count),
        ];
    }

    /**
     * @param  list<array{kind: string, part: string|null, figure: BigDecimal, ccy: string, month: string, date: string, category: array{id: int|null, name: string|null}|null}>  $facts
     * @return list<array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}>
     */
    private static function byCurrency(array $facts, Carbon $today, int $count): array
    {
        $months = self::months($today, $count);
        $report = [];

        // Currency::cases() order, so the report reads the same way as every picker.
        foreach (self::aggregate($facts, null) as $ccy => $byMonth) {
            $report[$ccy] = self::currencyReport($ccy, $byMonth, $months);
        }

        return array_values(array_filter(array_map(
            fn (Currency $currency) => $report[$currency->value] ?? null,
            Currency::cases()
        )));
    }

    /**
     * @param  list<array{kind: string, part: string|null, figure: BigDecimal, ccy: string, month: string, date: string, category: array{id: int|null, name: string|null}|null}>  $facts
     * @return array{report: array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}|null, unconverted: list<string>}
     */
    private static function inBase(array $facts, Carbon $today, int $count): array
    {
        $fx = Fx::for(Account::query()->distinct()->pluck('ccy')->all());
        $unconverted = [];
        $book = self::aggregate($facts, $fx, $unconverted);
        $base = Fx::BASE->value;

        return [
            'report' => isset($book[$base]) ? self::currencyReport($base, $book[$base], self::months($today, $count)) : null,
            'unconverted' => array_values(array_unique($unconverted)),
        ];
    }

    /** @return list<Carbon> */
    private static function months(Carbon $today, int $count): array
    {
        $months = [];

        for ($n = $count - 1; $n >= 0; $n--) {
            $months[] = $today->copy()->startOfMonth()->subMonthsNoOverflow($n);
        }

        return $months;
    }

    /**
     * Every row that is money in or money out, with the kind and the figure it is.
     *
     * What a row is does not depend on which currency it is reported in, so this is done
     * once and handed to the readings below. The date is carried because the base currency's
     * reading converts at the rate on the row's own day, and a month's rate is not that.
     *
     * @return list<array{kind: string, part: string|null, figure: BigDecimal, ccy: string, month: string, date: string, category: array{id: int|null, name: string|null}|null}>
     */
    private static function facts(Carbon $today, int $count, bool $onDueDate): array
    {
        $first = $today->copy()->startOfMonth()->subMonthsNoOverflow($count - 1);
        $last = $today->copy()->endOfMonth();

        $rows = Transaction::query()
            ->with(['meta', 'account', 'category'])
            ->whereHas('account', fn ($q) => $q->whereIn('type', self::accountTypes()))
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->when(
                $onDueDate,
                fn (Builder $q) => $q
                    ->where(fn (Builder $q) => self::whereCounted($q, '>=', $first->toDateString()))
                    ->where(fn (Builder $q) => self::whereCounted($q, '<=', $last->toDateString())),
                fn (Builder $q) => $q->whereBetween('date', [$first->toDateString(), $last->toDateString()]),
            )
            ->get();

        $partners = self::partnersOf($rows);

        $facts = [];

        foreach ($rows as $row) {
            $flow = self::classify($row, $partners[$row->id] ?? null);

            if ($flow === null) {
                continue;
            }

            [$kind, $figure] = $flow;

            $facts[] = [
                'kind' => $kind,
                'part' => self::part($row, $kind),
                'figure' => $figure,
                'ccy' => $row->account->ccy,
                'month' => substr($onDueDate ? self::countedOn($row) : $row->date, 0, 7),
                'date' => $row->date,
                'category' => $kind === 'spending' ? ['id' => $row->category_id, 'name' => $row->category?->name] : null,
            ];
        }

        return $facts;
    }

    /**
     * The day a row counts on by due date: a charge's due date, and any other row's own date. A charge
     * with no due date is on a card with no statement cycle, so its own date is all it has.
     */
    public static function countedOn(Transaction $row): string
    {
        $due = $row->type === TransactionType::Charge->value ? ($row->meta?->meta['due_date'] ?? null) : null;

        return $due ?? $row->date;
    }

    /**
     * countedOn() in SQL, for the report's window and for the transactions list a month links
     * to: filtered on the date column instead, a charge due this month but made last month is
     * counted here and listed there.
     */
    public static function whereCounted(Builder $q, string $operator, string $day): Builder
    {
        $charge = TransactionType::Charge->value;
        $due = fn (Builder $bag) => $bag->whereNotNull('meta->due_date');

        return $q
            ->where(fn (Builder $q) => $q
                ->where('type', $charge)
                ->whereHas('meta', fn (Builder $bag) => $bag->where('meta->due_date', $operator, $day)))
            ->orWhere(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('type', '!=', $charge)->orWhereDoesntHave('meta', $due))
                ->where('date', $operator, $day));
    }

    /**
     * The facts by currency and month: each in its own currency's money, or with $fx, in the
     * base currency's at the rate on its own day.
     *
     * @param  list<array{kind: string, part: string|null, figure: BigDecimal, ccy: string, month: string, date: string, category: array{id: int|null, name: string|null}|null}>  $facts
     * @param  list<string>  $unconverted  filled with the currency of every row left out
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function aggregate(array $facts, ?Fx $fx, array &$unconverted = []): array
    {
        $book = [];
        $rates = [];

        foreach ($facts as $fact) {
            $figure = $fact['figure'];
            $ccy = $fact['ccy'];

            if ($fx !== null) {
                $rate = $rates[$ccy][$fact['date']] ??= $fx->rate($ccy, $fact['date']);

                if ($rate === null) {
                    $unconverted[] = $ccy;

                    continue;
                }

                $figure = $figure->multipliedBy($rate)->toScale(TransactionMetaData::AMOUNT_SCALE, RoundingMode::HalfUp);
                $ccy = Fx::BASE->value;
            }

            $entry = &$book[$ccy][$fact['month']];
            $entry[$fact['kind']] = ($entry[$fact['kind']] ?? BigDecimal::zero())->plus($figure);

            if ($fact['part'] !== null) {
                $entry[$fact['part']] = ($entry[$fact['part']] ?? BigDecimal::zero())->plus($figure);
            }

            if ($fact['category'] !== null) {
                $key = $fact['category']['id'] ?? 0;
                $entry['categories'][$key] ??= [...$fact['category'], 'amount' => BigDecimal::zero()];
                $entry['categories'][$key]['amount'] = $entry['categories'][$key]['amount']->plus($figure);
            }

            unset($entry);
        }

        return $book;
    }

    /**
     * The account types whose rows move money a person holds, which is those with a
     * balance: a brokerage's side of a trade is counted from its bank row instead.
     *
     * @return list<string>
     */
    private static function accountTypes(): array
    {
        return array_values(array_map(
            fn (AccountType $type) => $type->value,
            array_filter(AccountType::cases(), fn (AccountType $type) => $type->hasBalance())
        ));
    }

    /**
     * Each paired row's partner, read in one query rather than one per row.
     *
     * @param  iterable<Transaction>  $rows
     * @return array<int, Transaction>
     */
    private static function partnersOf(iterable $rows): array
    {
        $pairs = [];

        foreach ($rows as $row) {
            $partner = $row->meta?->meta['paired_transaction_id'] ?? null;

            if ($partner !== null) {
                $pairs[$row->id] = (int) $partner;
            }
        }

        $found = Transaction::with('account')->whereIn('id', array_unique($pairs))->get()->keyBy('id');

        return array_filter(array_map(fn (int $id) => $found[$id] ?? null, $pairs));
    }

    /**
     * A row's kind and figure, or null for money that only moved between these accounts.
     * The direction is movesBalanceOn()'s, so a new type is classified rather than dropped.
     *
     * @return array{0: string, 1: BigDecimal}|null
     */
    private static function classify(Transaction $row, ?Transaction $partner): ?array
    {
        $accountType = AccountType::from($row->account->type);
        $sign = TransactionType::from($row->type)->movesBalanceOn($accountType);

        if ($sign === 0) {
            return null;
        }

        $amount = BigDecimal::of($row->amount);

        if ($accountType === AccountType::Card) {
            // A payment is the card half of a repayment, or a refund; neither is income.
            if ($sign > 0) {
                return null;
            }

            $stated = $row->meta?->meta['card_amount'] ?? null;

            return ['spending', $stated === null ? $amount : BigDecimal::of($stated)];
        }

        if ($partner !== null && $partner->account?->type === AccountType::Card->value) {
            return null;
        }

        // Signed, so a sell's proceeds take back what a buy put in.
        if ($partner !== null && TransactionType::from($partner->type)->derivesAmount()) {
            return ['invested', $sign < 0 ? $amount : $amount->negated()];
        }

        return $sign > 0 ? ['income', $amount] : ['spending', $amount];
    }

    /**
     * The share of its kind a row is broken out as: a dividend of income, a card charge of
     * spending. The rest of each kind is the other share, taken by subtraction in
     * currencyReport() rather than summed, so the two always add up to the kind's figure.
     */
    private static function part(Transaction $row, string $kind): ?string
    {
        return match (true) {
            $kind === 'income' && $row->type === TransactionType::Dividend->value => 'dividend',
            $kind === 'spending' && $row->account->type === AccountType::Card->value => 'card_spending',
            default => null,
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $byMonth
     * @param  list<Carbon>  $months
     * @return array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}
     */
    private static function currencyReport(string $ccy, array $byMonth, array $months): array
    {
        $zero = BigDecimal::zero();
        $totals = array_fill_keys(['income', 'dividend', 'other_income', 'spending', 'card_spending', 'cash_spending', 'net', 'invested'], $zero);
        $rows = [];

        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $entry = $byMonth[$key] ?? [];

            $figures = [
                'income' => $entry['income'] ?? $zero,
                'dividend' => $entry['dividend'] ?? $zero,
                'spending' => $entry['spending'] ?? $zero,
                'card_spending' => $entry['card_spending'] ?? $zero,
                'invested' => $entry['invested'] ?? $zero,
            ];
            $figures['other_income'] = $figures['income']->minus($figures['dividend']);
            $figures['cash_spending'] = $figures['spending']->minus($figures['card_spending']);
            $figures['net'] = $figures['income']->minus($figures['spending']);

            foreach ($totals as $name => $total) {
                $totals[$name] = $total->plus($figures[$name]);
            }

            $categories = array_values($entry['categories'] ?? []);
            usort($categories, fn (array $a, array $b) => $b['amount']->compareTo($a['amount']));

            $rows[] = [
                'month' => $key,
                'from' => $month->toDateString(),
                'to' => $month->copy()->endOfMonth()->toDateString(),
                ...array_map(fn (BigDecimal $figure) => self::money($figure), $figures),
                'categories' => array_map(fn (array $category) => [
                    ...$category,
                    'amount' => self::money($category['amount']),
                ], $categories),
            ];
        }

        return [
            'ccy' => $ccy,
            'months' => $rows,
            'totals' => array_map(fn (BigDecimal $total) => self::money($total), $totals),
        ];
    }

    private static function money(BigDecimal $value): string
    {
        return $value->toScale(TransactionMetaData::AMOUNT_SCALE)->toString();
    }
}
