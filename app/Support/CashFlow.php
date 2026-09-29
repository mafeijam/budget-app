<?php

namespace App\Support;

use App\DTO\TransactionMetaData;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Carbon\Carbon;

/**
 * Income, spending and money invested, per currency and month.
 *
 * Money moved between these accounts is neither. A card repayment's bank withdrawal is
 * left out, or every purchase would count twice -- once charged, once paid -- and a
 * trade's cash side is invested rather than spent. Both are told apart by the row the
 * bank row is paired with, so nothing has to be tagged by hand.
 *
 * A charge counts on its own date, in the card's currency at its card_amount, the figure
 * AccountBalance sums. Nothing is converted, so each currency is its own report.
 */
class CashFlow
{
    public const MONTHS = 12;

    /**
     * @return list<array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}>
     */
    public static function lastMonths(Carbon $today, int $count = self::MONTHS): array
    {
        $first = $today->copy()->startOfMonth()->subMonthsNoOverflow($count - 1);
        $last = $today->copy()->endOfMonth();

        $rows = Transaction::query()
            ->with(['meta', 'account', 'category'])
            ->whereHas('account', fn ($q) => $q->whereIn('type', self::accountTypes()))
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->whereBetween('date', [$first->toDateString(), $last->toDateString()])
            ->get();

        $partners = self::partnersOf($rows);

        $book = [];

        foreach ($rows as $row) {
            $flow = self::classify($row, $partners[$row->id] ?? null);

            if ($flow === null) {
                continue;
            }

            [$kind, $figure] = $flow;
            $ccy = $row->account->ccy;
            $month = substr($row->date, 0, 7);

            $entry = &$book[$ccy][$month];
            $entry[$kind] = ($entry[$kind] ?? BigDecimal::zero())->plus($figure);

            if ($kind === 'spending') {
                $key = $row->category_id ?? 0;
                $entry['categories'][$key] ??= ['id' => $row->category_id, 'name' => $row->category?->name, 'amount' => BigDecimal::zero()];
                $entry['categories'][$key]['amount'] = $entry['categories'][$key]['amount']->plus($figure);
            }

            unset($entry);
        }

        $months = [];

        for ($n = $count - 1; $n >= 0; $n--) {
            $months[] = $today->copy()->startOfMonth()->subMonthsNoOverflow($n);
        }

        $report = [];

        // Currency::cases() order, so the report reads the same way as every picker.
        foreach (Currency::cases() as $currency) {
            if (! isset($book[$currency->value])) {
                continue;
            }

            $report[] = self::currencyReport($currency->value, $book[$currency->value], $months);
        }

        return $report;
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
     * @param  array<string, array<string, mixed>>  $byMonth
     * @param  list<Carbon>  $months
     * @return array{ccy: string, months: list<array<string, mixed>>, totals: array<string, string>}
     */
    private static function currencyReport(string $ccy, array $byMonth, array $months): array
    {
        $zero = BigDecimal::zero();
        $totals = ['income' => $zero, 'spending' => $zero, 'net' => $zero, 'invested' => $zero];
        $rows = [];

        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $entry = $byMonth[$key] ?? [];

            $figures = [
                'income' => $entry['income'] ?? $zero,
                'spending' => $entry['spending'] ?? $zero,
                'invested' => $entry['invested'] ?? $zero,
            ];
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
