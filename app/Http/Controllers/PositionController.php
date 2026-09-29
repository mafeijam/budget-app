<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;
use App\Support\Fx;
use App\Support\Positions;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    public function index(Request $r)
    {
        // A past day picked to look back on, or today. A malformed or future day is today,
        // since no price is known past it.
        $today = today();
        $at = $r->input('at');
        $at = is_string($at) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) && checkdate(
            (int) substr($at, 5, 2), (int) substr($at, 8, 2), (int) substr($at, 0, 4)
        ) && $at < $today->toDateString() ? $at : $today->toDateString();

        $brokers = Account::query()
            ->where('type', AccountType::Security->value)
            ->with('meta')
            ->orderBy('name')
            ->get();

        // A pending dividend has not paid.
        // Paid into the bank, and tagged with the brokerage whose holding paid it. By
        // symbol as well, for the table to break the brokerage's total down: it matches a
        // row by that string, so the symbol is normalised the way a trade's is.
        $dividends = Transaction::query()
            ->where('type', TransactionType::Dividend->value)
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->where('date', '<=', $at)
            ->with('meta')
            ->get(['id', 'amount'])
            ->groupBy(fn (Transaction $row) => (int) ($row->meta?->meta['brokerage_account_id'] ?? 0))
            ->map(fn ($rows) => $rows->groupBy(
                fn (Transaction $row) => Positions::symbol($row->meta?->meta['symbol'] ?? '')
            ));

        $brokerages = $brokers
            ->map(function (Account $broker) use ($dividends, $at) {
                $valued = Positions::valued($broker, $at);
                $received = $dividends[$broker->id] ?? collect();
                $paying = fn (string $symbol) => $received->get($symbol, collect());

                $sum = fn (iterable $values) => (string) collect($values)
                    ->reduce(fn (BigDecimal $total, $value) => $total->plus($value), BigDecimal::zero())
                    ->toScale(4);

                $positions = array_map(function (array $position) use ($paying, $sum) {
                    $payments = $paying($position['symbol']);
                    $dividends = $sum($payments->pluck('amount'));

                    // An open position's price is what makes its unrealised leg known; a
                    // sold-out one has none because nothing is held.
                    $unpriced = $position['open'] && $position['market_value'] === null;

                    return $position + [
                        'dividends' => $dividends,
                        'dividend_count' => $payments->count(),
                        ...$this->pnl([
                            'unrealised' => $position['unrealised'],
                            'realised' => $position['realised'],
                            'dividends' => $dividends,
                            'open_cost' => $position['cost'],
                        ], $unpriced),
                    ];
                }, $valued['positions']);

                $totals = [...$valued['totals'], 'dividends' => $sum($received->collapse()->pluck('amount'))];

                return [
                    'id' => $broker->id,
                    'name' => $broker->name,
                    'ccy' => $broker->ccy,
                    'status' => $broker->status,
                    'settles_into' => $broker->settlementAccount()?->name,
                    // A symbol the brokerage never traded is in this total and in no row, so
                    // the column can read low. It is money received either way.
                    'positions' => $positions,
                    ...$totals,
                    ...$this->pnl($totals, $totals['unpriced'] > 0),
                ];
            })
            ->filter(fn (array $b) => $b['status'] === 'active' || collect($b['positions'])->contains('open', true))
            ->map(fn (array $b) => [...$b, 'combined' => $this->combined([$b], $at)])
            ->values();

        // Per currency, for the All view: nothing here converts HKD into USD.
        $totals = $brokerages
            ->groupBy('ccy')
            ->map(function ($group, string $ccy) {
                $sum = fn (string $key) => (string) $group
                    ->reduce(fn (BigDecimal $total, array $b) => $total->plus($b[$key]), BigDecimal::zero())
                    ->toScale(4);

                $totals = [
                    'ccy' => $ccy,
                    'market_value' => $sum('market_value'),
                    'unrealised' => $sum('unrealised'),
                    'open_cost' => $sum('open_cost'),
                    'realised' => $sum('realised'),
                    'fees' => $sum('fees'),
                    'dividends' => $sum('dividends'),
                    'unpriced' => $group->sum('unpriced'),
                ];

                return [...$totals, ...$this->pnl($totals, $totals['unpriced'] > 0)];
            })
            ->values();

        $combined = $this->combined($totals->all(), $at);

        // ISO 8601 with offset: max() returns a bare 'Y-m-d H:i:s', which new Date()
        // reads as browser-local time, or rejects in Safari.
        $latest = Price::where('source', 'yahoo')->max('updated_at');
        $pricesUpdatedAt = $latest === null ? null : Carbon::parse($latest)->toIso8601String();

        return inertia('position', [
            ...compact('brokerages', 'totals', 'combined', 'pricesUpdatedAt'),
            'base' => Fx::BASE->value,
            'at' => $at === $today->toDateString() ? null : $at,
            'today' => $today->toDateString(),
        ]);
    }

    /**
     * Unrealised plus realised plus dividends, and that over the cost held.
     *
     * Null P&L where a holding is unpriced: its cost is in none of the three legs, so a sum
     * would read as a profit on everything held while leaving that holding out of it. The
     * percentage is null wherever the P&L is, and where the cost held is nothing -- a
     * position sold out has banked its P&L and left no capital behind to be a return on.
     *
     * Fees are not in it, and neither is tax: realised is gross of both, and fees are a
     * figure of their own beside this one.
     *
     * @param  array<string, string|null>  $totals  unrealised, realised, dividends, open_cost
     * @param  bool  $unpriced  a holding missing from the unrealised leg
     * @return array{pnl: ?string, pnl_percent: ?string}
     */
    private function pnl(array $totals, bool $unpriced): array
    {
        if ($unpriced) {
            return ['pnl' => null, 'pnl_percent' => null];
        }

        // A sold-out position's unrealised is null because nothing is held, not because it
        // is unknown, so here it is a zero in the sum.
        $pnl = BigDecimal::of($totals['unrealised'] ?? '0')
            ->plus($totals['realised'])
            ->plus($totals['dividends'])
            ->toScale(4, RoundingMode::HalfUp);

        $cost = BigDecimal::of($totals['open_cost']);

        return [
            'pnl' => (string) $pnl,
            // Carried wide and rounded once, or a third of a cost reads a hundredth out.
            'pnl_percent' => $cost->isPositive()
                ? (string) $pnl->dividedBy($cost, 12, RoundingMode::HalfUp)
                    ->multipliedBy(100)->toScale(2, RoundingMode::HalfUp)
                : null,
        ];
    }

    /**
     * Totals summed in the base currency at $day's rate, or null when they are all in it
     * already and there is nothing to convert. A currency with no rate yet is left out and named, so
     * a total missing something says so.
     *
     * @param  list<array<string, mixed>>  $totals
     * @return array<string, mixed>|null
     */
    private function combined(array $totals, string $day): ?array
    {
        if (array_diff(array_column($totals, 'ccy'), [Fx::BASE->value]) === []) {
            return null;
        }

        $fx = Fx::for(array_column($totals, 'ccy'));
        $keys = ['market_value', 'unrealised', 'open_cost', 'realised', 'fees', 'dividends'];
        $sums = array_fill_keys($keys, BigDecimal::zero());
        $unconverted = [];
        $unpriced = 0;

        foreach ($totals as $total) {
            if ($fx->rate($total['ccy'], $day) === null) {
                $unconverted[] = $total['ccy'];

                continue;
            }

            foreach ($keys as $key) {
                $sums[$key] = $sums[$key]->plus($fx->toBase($total[$key], $total['ccy'], $day));
            }

            $unpriced += $total['unpriced'];
        }

        $summed = array_map(fn (BigDecimal $sum) => (string) $sum->toScale(4), $sums);

        return [
            'ccy' => Fx::BASE->value,
            ...$summed,
            'unpriced' => $unpriced,
            'unconverted' => $unconverted,
            // The same three legs at the day's rate, over the same unpriced rule: a holding
            // with no price is out of the unrealised leg here too.
            ...$this->pnl($summed, $unpriced > 0),
        ];
    }

    /** Synchronous on purpose: there is no queue worker. With `at`, up to that past day. */
    public function fetch(Request $r)
    {
        $at = $r->validate([
            'at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.today()->toDateString()],
        ])['at'] ?? null;

        $status = Artisan::call('prices:fetch', $at === null ? [] : ['--at' => $at]);

        $report = collect(preg_split('/\R/', trim(Artisan::output())))
            ->filter()
            ->implode('; ');

        return back()->with('message', ($status === 0 ? 'Prices fetched' : 'Some prices were not fetched')
            .($report === '' ? '' : ": {$report}"));
    }

    /** Marked manual, so the next fetch leaves it alone. */
    public function store(Request $r)
    {
        $input = $r->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('type', AccountType::Security->value)],
            'symbol' => ['required', 'string', 'max:32'],
            'close' => ['required', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
        ], [], ['account_id' => 'brokerage', 'close' => 'price']);

        $broker = Account::findOrFail($input['account_id']);
        $symbol = strtoupper(trim($input['symbol']));

        Price::updateOrCreate(
            ['symbol' => $symbol, 'date' => today()->toDateString()],
            ['close' => $input['close'], 'ccy' => $broker->ccy, 'source' => 'manual']
        );

        return back()->with('message', "Price for [{$symbol}] set to {$input['close']} {$broker->ccy}");
    }
}
