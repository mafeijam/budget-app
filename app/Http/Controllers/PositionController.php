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
    public function index()
    {
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
            ->with('meta')
            ->get(['id', 'amount'])
            ->groupBy(fn (Transaction $row) => (int) ($row->meta?->meta['brokerage_account_id'] ?? 0));

        $brokerages = $brokers
            ->map(function (Account $broker) use ($dividends) {
                $valued = Positions::valued($broker);
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

        $combined = $this->combined($totals->all());

        // ISO 8601 with offset: max() returns a bare 'Y-m-d H:i:s', which new Date()
        // reads as browser-local time, or rejects in Safari.
        $latest = Price::where('source', 'yahoo')->max('updated_at');
        $pricesUpdatedAt = $latest === null ? null : Carbon::parse($latest)->toIso8601String();

        return inertia('position', [...compact('brokerages', 'totals', 'combined', 'pricesUpdatedAt'), 'base' => Fx::BASE->value]);
    }

    /**
     * Every currency's totals in the base currency at today's rate, for the All view, or
     * null with only one currency. A currency with no rate yet is left out and named, so
     * a total missing something says so.
     *
     * @param  list<array<string, mixed>>  $totals
     * @return array<string, mixed>|null
     */
    private function combined(array $totals): ?array
    {
        if (count($totals) < 2) {
            return null;
        }

        $fx = Fx::for(array_column($totals, 'ccy'));
        $today = today()->toDateString();
        $keys = ['market_value', 'unrealised', 'open_cost', 'realised', 'fees', 'dividends'];
        $sums = array_fill_keys($keys, BigDecimal::zero());
        $unconverted = [];
        $unpriced = 0;

        foreach ($totals as $total) {
            if ($fx->rate($total['ccy'], $today) === null) {
                $unconverted[] = $total['ccy'];

                continue;
            }

            foreach ($keys as $key) {
                $sums[$key] = $sums[$key]->plus($fx->toBase($total[$key], $total['ccy'], $today));
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

    /** Synchronous on purpose: there is no queue worker. */
    public function fetch()
    {
        $status = Artisan::call('prices:fetch');

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
