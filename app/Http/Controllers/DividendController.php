<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Symbol;
use App\Models\Transaction;
use App\Support\Forecast;
use App\Support\Fx;
use App\Support\Positions;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * What the holdings have paid: a year month by month and symbol by symbol, and every year
 * beside it. In the base currency at the rate of the day each was paid, as the cash flow
 * report counts them, so a year's total is the dividend line of that report.
 */
class DividendController extends Controller
{
    public function index(Request $r)
    {
        $today = today();
        $rows = Transaction::query()
            ->with('meta')
            ->where('type', TransactionType::Dividend->value)
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->whereHas('account', fn ($q) => $q->where('type', AccountType::Cash->value))
            ->orderBy('date')
            ->get();

        $fx = Fx::for($rows->pluck('ccy')->push(Fx::BASE->value)->unique()->all());
        $unconverted = [];
        $paid = [];

        foreach ($rows as $row) {
            $base = $fx->toBase((string) $row->amount, $row->ccy, $row->date);

            if ($base === null) {
                $unconverted[] = ['ccy' => $row->ccy, 'broker' => $row->meta?->meta['brokerage_account_id'] ?? null];

                continue;
            }

            $paid[] = [
                'date' => $row->date,
                'year' => (int) substr($row->date, 0, 4),
                'month' => (int) substr($row->date, 5, 2),
                'symbol' => Positions::symbol($row->meta?->meta['symbol'] ?? '') ?: '—',
                'broker' => $row->meta?->meta['brokerage_account_id'] ?? null,
                'amount' => $base,
            ];
        }

        $everyPayment = collect($paid);

        $sum = fn ($list) => $list->reduce(fn (BigDecimal $total, array $p) => $total->plus($p['amount']), BigDecimal::zero());
        $money = fn (BigDecimal $value) => (string) $value->toScale(4);

        // The brokerages that have ever paid, for the page's picker: one that never has is a
        // page of nothing.
        $brokerNames = Account::query()
            ->whereIn('id', $everyPayment->pluck('broker')->filter()->unique())
            ->orderBy('name')
            ->pluck('name', 'id');

        $brokers = $brokerNames->map(fn (string $name, int $id) => [
            'id' => $id,
            'name' => $name,
            'total' => $money($sum($everyPayment->where('broker', $id))),
        ])->values();

        // This year always, so the page opens on it even before its first payment. Years from
        // every payment, not the ones a brokerage is narrowed to: a year one brokerage did not
        // pay in is an empty column, which is what says the year went by without it, and a
        // year list that moved with the brokerage would move the year under the reader.
        $years = $everyPayment->pluck('year')->push($today->year)->unique()->sortDesc()->values();
        $year = in_array((int) $r->input('year'), $years->all(), true) ? (int) $r->input('year') : $today->year;

        // What the rest of this year is expected to pay, as the forecast has it: only this
        // year's page has days still to come. Converted once, each still naming the brokerage
        // whose holding it is derived from, for the views below to narrow.
        $expected = collect();

        if ($year === $today->year) {
            $endOfYear = $today->copy()->endOfYear()->toDateString();

            foreach (Forecast::for($today, 12)->expectedDividendList() as $dividend) {
                $base = $dividend['date'] <= $endOfYear ? $fx->toBase($dividend['amount'], $dividend['ccy'], $today->toDateString()) : null;

                if ($base !== null) {
                    $expected->push([
                        'broker' => $dividend['broker'],
                        'month' => (int) substr($dividend['date'], 5, 2),
                        'symbol' => $dividend['symbol'],
                        'amount' => $base,
                    ]);
                }
            }
        }

        $context = [
            'paid' => $everyPayment,
            'expected' => $expected,
            'unconverted' => collect($unconverted),
            'years' => $years,
            'year' => $year,
            'brokerNames' => $brokerNames,
            'costs' => $this->costsHeld($year, $today),
            'sum' => $sum,
            'money' => $money,
        ];

        return inertia('dividend', [
            'year' => $year,
            'base' => Fx::BASE->value,
            'today' => $today->toDateString(),
            'brokers' => $brokers->all(),
            // The page of every brokerage together: what it is when none is chosen.
            ...$this->view(0, $context),
            // And of each brokerage alone, all sent: the choice is the browser's, kept there
            // as the Positions page keeps its own, and switching it is no request. Narrowing
            // here rather than there because these figures are sums over rows the page does
            // not carry, by symbol and by month.
            'byBroker' => $brokers->mapWithKeys(fn (array $b) => [$b['id'] => $this->view($b['id'], $context)])->all(),
        ]);
    }

    /**
     * The page's figures for one brokerage's payments, or for all of them with 0.
     *
     * @param  array<string, mixed>  $c  what index() has read, shared by every view
     * @return array<string, mixed>
     */
    private function view(int $broker, array $c): array
    {
        ['sum' => $sum, 'money' => $money, 'year' => $year] = $c;

        $paid = $broker ? $c['paid']->where('broker', $broker) : $c['paid'];

        // A bonus or a double pay is no brokerage's, so it is left out of one's page.
        $expected = $broker ? $c['expected']->filter(fn (array $e) => (int) $e['broker'] === $broker) : $c['expected'];

        $inYear = $paid->where('year', $year);
        $lastYear = $paid->where('year', $year - 1);

        [$cost, $costUnconverted] = $this->costOf($c['costs'], $broker);

        $months = fn ($list) => array_map(
            fn (int $m) => $money($sum($list->where('month', $m))),
            range(1, 12)
        );

        $symbols = $inYear->pluck('symbol')->merge($expected->pluck('symbol'))->unique()->values();

        // Every symbol that has ever paid, not just this year's: a symbol no longer held
        // is exactly the one whose line across the years is worth reading, so the year's
        // bars are filtered by a list of all of them rather than of this year's.
        $allSymbols = $paid->pluck('symbol')->unique()->sort()->values();
        $allNames = Symbol::namesFor($allSymbols->all());
        $names = Symbol::namesFor($symbols->all());
        $brokerNames = $c['brokerNames'];

        $bySymbol = $symbols->map(function (string $symbol) use ($inYear, $lastYear, $expected, $sum, $money, $months, $names, $brokerNames) {
            $own = $inYear->where('symbol', $symbol);

            return [
                'symbol' => $symbol,
                'name' => $names[$symbol] ?? null,
                'months' => $months($own),
                'expected_months' => $months($expected->where('symbol', $symbol)),
                'total' => $money($sum($own)),
                'expected' => $money($sum($expected->where('symbol', $symbol))),
                'previous' => $money($sum($lastYear->where('symbol', $symbol))),
                'payments' => $own->count(),
                'last' => $own->last()['date'] ?? null,
                'brokers' => $own->pluck('broker')->filter()->unique()
                    ->map(fn ($id) => $brokerNames[$id] ?? null)->filter()->values()->all(),
            ];
        })->sortByDesc(fn (array $s) => (float) $s['total'] + (float) $s['expected'])->values()->all();

        return [
            // What the holdings cost, for the yield the year's payments are a share of, and
            // the currencies it could not be counted in for want of a rate.
            'cost' => $cost,
            'costUnconverted' => $costUnconverted,
            'years' => $c['years']->map(fn (int $y) => [
                'year' => $y,
                'total' => $money($sum($paid->where('year', $y))),
                // What each symbol paid that year, so the year's bars can be read one
                // symbol at a time. A pass over the rows already held rather than a
                // query a year, which on a decade of dividends is the difference
                // between one read and eleven.
                'bySymbol' => $paid->where('year', $y)
                    ->groupBy('symbol')
                    ->map(fn ($list) => $money($sum($list)))
                    ->all(),
            ])->all(),
            'total' => $money($sum($inYear)),
            'previous' => $money($sum($lastYear)),
            'expected' => $money($sum($expected)),
            'payments' => $inYear->count(),
            'months' => $months($inYear),
            'expectedMonths' => $months($expected),
            'previousMonths' => $months($lastYear),
            'symbols' => $bySymbol,
            // Every symbol that has ever paid, biggest first, and it is the order the
            // colours come from: what a symbol has paid in all, not in the year on screen.
            // Ranked per year, the order moves with the year and so does every colour --
            // 1883.HK was orange on 2024 and grey in Others on 2026 -- which makes a
            // symbol's colour mean a different symbol each time the year changes.
            //
            // Also the picker's list, and the two figures that make an entry in it worth
            // reading: the name it is known by, and everything it has paid, which is
            // neither this year's total nor any one of the bars. And the years it has paid
            // in, so a symbol on one bar reads as a different thing from one on nine.
            'allSymbols' => $allSymbols->map(function (string $code) use ($paid, $sum, $money, $allNames) {
                $own = $paid->where('symbol', $code);

                return [
                    'symbol' => $code,
                    'name' => $allNames[$code] ?? null,
                    'total' => $money($sum($own)),
                    'years' => $own->pluck('year')->unique()->count(),
                ];
            })->sortByDesc('total')->values()->all(),
            // What is still to come, per symbol. The dashed top on this year's bar is a
            // whole-year figure, so a symbol filtered onto that bar needs its own share of
            // it -- or the bar shows the expectation of every symbol at once, which is
            // worse than not filtering. Empty on any other year, as `expected` is, and the
            // bar that draws it is this year's alone.
            'expectedBySymbol' => $expected->groupBy('symbol')
                ->map(fn ($list) => $money($sum($list)))
                ->all(),
            // The currencies with no rate, among the payments this page is of: another
            // brokerage's missing rate is no reason to say part of this one is left out.
            'unconverted' => $c['unconverted']
                ->when($broker, fn ($list) => $list->where('broker', $broker))
                ->pluck('ccy')->unique()->values()->all(),
        ];
    }

    /**
     * What each brokerage holds cost, in the base currency, as the yield on it is read
     * against: the open positions' remaining cost on the last day of the year, or today's on
     * the year in progress, at the rate of that day. One entry a brokerage that holds
     * anything, its figure null where its currency has no rate that day.
     *
     * The day is the year's end and not today even for a past year: a payment is a share of
     * what was held while it was paid, and today's cost holds positions bought since and
     * leaves out ones sold, which would put a different year's capital under this year's
     * money. It is still not exact -- a position sold during the year paid but is not in the
     * cost at its end -- and a yield on a book that turned over reads a little high.
     *
     * @return list<array{id: int, ccy: string, base: BigDecimal|null}>
     */
    private function costsHeld(int $year, Carbon $today): array
    {
        $on = $year === $today->year ? $today->toDateString() : "{$year}-12-31";

        $accounts = Account::query()->where('type', AccountType::Security->value)->get();

        $fx = Fx::for($accounts->pluck('ccy')->push(Fx::BASE->value)->unique()->all());
        $costs = [];

        foreach ($accounts as $account) {
            $held = array_filter(
                Positions::fromTrades(array_values(array_filter(
                    Positions::tradesOf($account),
                    fn (array $trade) => $trade['date'] <= $on
                ))),
                fn (array $position) => $position['open']
            );

            $own = array_reduce($held, fn (BigDecimal $sum, array $p) => $sum->plus($p['cost']), BigDecimal::zero());

            if ($own->isZero()) {
                continue;
            }

            $costs[] = [
                'id' => $account->id,
                'ccy' => $account->ccy,
                'base' => $fx->toBase((string) $own, $account->ccy, $on),
            ];
        }

        return $costs;
    }

    /**
     * One brokerage's cost, or every brokerage's with 0, and the currencies that could not be
     * counted.
     *
     * Null when any is missing a rate: a cost missing a brokerage reads as a yield on less
     * than was held, which is higher, and nothing on the page would say so.
     *
     * @param  list<array{id: int, ccy: string, base: BigDecimal|null}>  $costs
     * @return array{0: string|null, 1: list<string>}
     */
    private function costOf(array $costs, int $broker): array
    {
        $mine = array_filter($costs, fn (array $c) => ! $broker || $c['id'] === $broker);
        $missing = array_values(array_unique(array_column(array_filter($mine, fn (array $c) => $c['base'] === null), 'ccy')));

        $total = array_reduce(
            $mine,
            fn (BigDecimal $sum, array $c) => $c['base'] === null ? $sum : $sum->plus($c['base']),
            BigDecimal::zero()
        );

        return [$missing === [] ? (string) $total->toScale(4) : null, $missing];
    }
}
