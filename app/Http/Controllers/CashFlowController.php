<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Support\CashFlow;
use App\Support\Fx;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

class CashFlowController extends Controller
{
    /** Rows sent for a quick view, the largest first; the count and the total are of them all. */
    private const PEEK_LIMIT = 500;

    public function index()
    {
        // Every view the page can ask for -- card spending by due date or by charge date, for
        // all currencies in the base or each in its own money -- so the two choices are the
        // browser's to keep, like the other pages' dropdowns, and a change costs no visit.
        // By due date is the default for a reason: it puts card spending on the same clock as
        // cash spending, the month the money left the bank.
        $views = [];
        $seen = [];

        foreach (['due' => true, 'charged' => false] as $card => $onDueDate) {
            // today() is Hong Kong's, so the current month turns over when the app's day does.
            $both = CashFlow::both(today(), onDueDate: $onDueDate);
            $seen = array_merge($seen, $both['currencies']);
            $unconverted = $both['combined']['unconverted'];

            $views[$card] = ['all' => [
                'report' => array_values(array_filter([$both['combined']['report']])),
                'unconverted' => $unconverted,
            ]];

            foreach ($both['by_currency'] as $section) {
                $views[$card][$section['ccy']] = ['report' => [$section], 'unconverted' => $unconverted];
            }
        }

        // Currency::cases() order, as every picker lists them, and each once.
        $currencies = array_values(array_filter(
            array_column(Currency::cases(), 'value'),
            fn (string $code) => in_array($code, $seen, true)
        ));

        return inertia('cash-flow', [
            'views' => $views,
            'currencies' => $currencies,
            'base' => Fx::BASE->value,
            'months' => CashFlow::MONTHS,
        ]);
    }

    /**
     * The transactions behind one tile of the breakdown, for the page's quick view: the rows
     * the report added up to make that category's figure, in a window of days.
     *
     * An endpoint of its own, because the page cannot carry them: every category of every month
     * is a few thousand rows, read by whoever opens one in a hundred. The rows are the report's
     * own (see CashFlow::spendingRows()), so the list adds up to the tile. In the base currency
     * each is also given converted at its own day's rate, as the report converts it; a row with
     * no rate is listed without a base figure and left out of the total, which says so.
     */
    public function transactions(Request $r)
    {
        $data = $r->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'card' => ['nullable', 'in:due,charged'],
            // An id, or `none` for the rows with no category.
            'category' => ['required', 'regex:/^(none|\d+)$/'],
            'ccy' => ['nullable', 'in:'.implode(',', array_column(Currency::cases(), 'value'))],
        ]);

        $category = $data['category'] === 'none' ? null : (int) $data['category'];

        $rows = CashFlow::spendingRows($data['from'], $data['to'], ($data['card'] ?? 'due') === 'due', $category)
            ->when(
                ! empty($data['ccy']),
                fn ($q) => $q->whereHas('account', fn ($account) => $account->where('ccy', $data['ccy']))
            )
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        // One currency in its own money, or everything in the base at the day's rate.
        $own = ! empty($data['ccy']);
        $fx = $own ? null : Fx::for($rows->pluck('account.ccy')->all());
        $total = BigDecimal::zero();
        $unconverted = 0;

        $listed = $rows->map(function ($row) use ($own, $fx, &$total, &$unconverted) {
            $figure = CashFlow::spendingFigure($row);
            $base = $own ? $figure : $fx->toBase((string) $figure, $row->account->ccy, $row->date);

            $base === null ? $unconverted++ : $total = $total->plus($base);

            return [
                'id' => $row->id,
                'date' => $row->date,
                'counted_on' => CashFlow::countedOn($row),
                'description' => $row->description,
                'account' => $row->account->name,
                'ccy' => $row->account->ccy,
                'status' => $row->status,
                'one_off' => (bool) ($row->meta?->meta['one_off'] ?? false),
                'amount' => (string) $figure->toScale(4),
                'base' => $base === null ? null : (string) $base->toScale(4),
            ];
        });

        return response()->json([
            'count' => $listed->count(),
            'rows' => $listed->sortByDesc(fn (array $row) => (float) ($row['base'] ?? $row['amount']))->take(self::PEEK_LIMIT)->values(),
            'ccy' => $own ? $data['ccy'] : Fx::BASE->value,
            'total' => (string) $total->toScale(4),
            'unconverted' => $unconverted,
        ]);
    }
}
