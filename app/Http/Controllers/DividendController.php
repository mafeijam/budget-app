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
        // page of nothing. Taken before the page is narrowed, or choosing one would leave it
        // the only option.
        $brokerNames = Account::query()
            ->whereIn('id', $everyPayment->pluck('broker')->filter()->unique())
            ->orderBy('name')
            ->pluck('name', 'id');

        $brokers = $brokerNames->map(fn (string $name, int $id) => [
            'id' => $id,
            'name' => $name,
            'total' => $money($sum($everyPayment->where('broker', $id))),
        ])->values();

        // One brokerage's dividends, or 0 for all of them. Anything that names none of the
        // brokerages above is all of them rather than an empty page: a hand-edited URL, or a
        // brokerage whose payments have since been moved.
        $broker = $brokers->contains('id', (int) $r->input('broker')) ? (int) $r->input('broker') : 0;

        $paid = $broker ? $everyPayment->where('broker', $broker) : $everyPayment;

        // This year always, so the page opens on it even before its first payment. Years from
        // every payment, not the ones narrowed to: a year one brokerage did not pay in is an
        // empty column, which is what says the year went by without it, and a year list that
        // moved with the brokerage would move the year under the reader's cursor.
        $years = $everyPayment->pluck('year')->push($today->year)->unique()->sortDesc()->values();
        $year = in_array((int) $r->input('year'), $years->all(), true) ? (int) $r->input('year') : $today->year;

        $inYear = $paid->where('year', $year);
        $lastYear = $paid->where('year', $year - 1);

        // What the rest of this year is expected to pay, as the forecast has it: only this
        // year's page has days still to come.
        $expected = collect();

        if ($year === $today->year) {
            $endOfYear = $today->copy()->endOfYear()->toDateString();

            foreach (Forecast::for($today, 12)->expectedDividendList() as $dividend) {
                // A bonus or a double pay is no brokerage's, so it is left out of one's page.
                if ($broker && (int) $dividend['broker'] !== $broker) {
                    continue;
                }

                $base = $dividend['date'] <= $endOfYear ? $fx->toBase($dividend['amount'], $dividend['ccy'], $today->toDateString()) : null;

                if ($base !== null) {
                    $expected->push([
                        'month' => (int) substr($dividend['date'], 5, 2),
                        'symbol' => $dividend['symbol'],
                        'amount' => $base,
                    ]);
                }
            }
        }

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

        return inertia('dividend', [
            'year' => $year,
            'broker' => $broker,
            'brokers' => $brokers->all(),
            'years' => $years->map(fn (int $y) => [
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
            'base' => Fx::BASE->value,
            'today' => $today->toDateString(),
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
            'unconverted' => collect($unconverted)
                ->when($broker, fn ($list) => $list->where('broker', $broker))
                ->pluck('ccy')->unique()->values()->all(),
        ]);
    }
}
