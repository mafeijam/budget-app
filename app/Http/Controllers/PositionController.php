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
        // Paid into the bank, and tagged with the brokerage whose holding paid it.
        $dividends = Transaction::query()
            ->where('type', TransactionType::Dividend->value)
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->where('date', '<=', $at)
            ->with('meta')
            ->get(['id', 'amount'])
            ->groupBy(fn (Transaction $row) => (int) ($row->meta?->meta['brokerage_account_id'] ?? 0));

        $brokerages = $brokers
            ->map(function (Account $broker) use ($dividends, $at) {
                $valued = Positions::valued($broker, $at);
                $positions = $valued['positions'];

                $sum = fn (iterable $values) => (string) collect($values)
                    ->reduce(fn (BigDecimal $total, $value) => $total->plus($value), BigDecimal::zero())
                    ->toScale(4);

                return [
                    'id' => $broker->id,
                    'name' => $broker->name,
                    'ccy' => $broker->ccy,
                    'status' => $broker->status,
                    'settles_into' => $broker->settlementAccount()?->name,
                    'positions' => $positions,
                    ...$valued['totals'],
                    'dividends' => $sum(($dividends[$broker->id] ?? collect())->pluck('amount')),
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

                return [
                    'ccy' => $ccy,
                    'market_value' => $sum('market_value'),
                    'unrealised' => $sum('unrealised'),
                    'open_cost' => $sum('open_cost'),
                    'realised' => $sum('realised'),
                    'fees' => $sum('fees'),
                    'dividends' => $sum('dividends'),
                    'unpriced' => $group->sum('unpriced'),
                ];
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

        return [
            'ccy' => Fx::BASE->value,
            ...array_map(fn (BigDecimal $sum) => (string) $sum->toScale(4), $sums),
            'unpriced' => $unpriced,
            'unconverted' => $unconverted,
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
