<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;
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
        $dividends = Transaction::query()
            ->whereIn('account_id', $brokers->pluck('id'))
            ->where('type', TransactionType::Dividend->value)
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->get(['account_id', 'amount'])
            ->groupBy('account_id');

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

        // ISO 8601 with offset: max() returns a bare 'Y-m-d H:i:s', which new Date()
        // reads as browser-local time, or rejects in Safari.
        $latest = Price::where('source', 'yahoo')->max('updated_at');
        $pricesUpdatedAt = $latest === null ? null : Carbon::parse($latest)->toIso8601String();

        return inertia('position', compact('brokerages', 'totals', 'pricesUpdatedAt'));
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
