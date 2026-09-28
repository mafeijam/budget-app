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

/**
 * What each brokerage holds, read from its trades by Positions. Read-only: a position
 * changes by recording a trade, never by editing the position.
 */
class PositionController extends Controller
{
    public function index()
    {
        $brokers = Account::query()
            ->where('type', AccountType::Security->value)
            ->with('meta')
            ->orderBy('name')
            ->get();

        // Received dividends only, as a balance counts them: a pending one has not paid.
        $dividends = Transaction::query()
            ->whereIn('account_id', $brokers->pluck('id'))
            ->where('type', TransactionType::Dividend->value)
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->get(['account_id', 'amount'])
            ->groupBy('account_id');

        $brokerages = $brokers
            ->map(function (Account $broker) use ($dividends) {
                // One valuation for this page and the accounts list -- see Positions::valued().
                $valued = Positions::valued($broker);
                $positions = $valued['positions'];

                // Summed like the totals valued() gives, within this brokerage's currency.
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
            // A closed brokerage with nothing in it is history; one still holding shares is not.
            ->filter(fn (array $b) => $b['status'] === 'active' || collect($b['positions'])->contains('open', true))
            ->values();

        // When a fetch last wrote a price, beside the button that runs one. A timestamp
        // rather than a close date: each price already says which day it closed, and
        // this says how fresh the table is. Manual prices are not a fetch.
        //
        // Sent as ISO 8601 with its offset, as every other timestamp reaches the page:
        // max() returns the column's bare 'Y-m-d H:i:s', which new Date() reads as the
        // browser's own local time, or rejects outright in Safari.
        $latest = Price::where('source', 'yahoo')->max('updated_at');
        $pricesUpdatedAt = $latest === null ? null : Carbon::parse($latest)->toIso8601String();

        return inertia('position', compact('brokerages', 'pricesUpdatedAt'));
    }

    /**
     * Run the price fetch now rather than waiting for the morning's scheduled run.
     *
     * The same command, not a copy of its work, so a fetch from here and from the
     * schedule cannot differ. Run in the request rather than queued: it is a few
     * symbols and a few seconds, and there is no queue worker to hand it to. What it
     * printed comes back as the page's message, a line per symbol, so a symbol Yahoo
     * refused says so rather than the button appearing to have done nothing.
     */
    public function fetch()
    {
        $status = Artisan::call('prices:fetch');

        $report = collect(preg_split('/\R/', trim(Artisan::output())))
            ->filter()
            ->implode('; ');

        return back()->with('message', ($status === 0 ? 'Prices fetched' : 'Some prices were not fetched')
            .($report === '' ? '' : ": {$report}"));
    }

    /**
     * Set a symbol's price for today by hand, for a symbol Yahoo cannot price or a
     * close it got wrong. Marked manual, so the next fetch leaves it alone.
     *
     * The currency is the brokerage's, which is every trade's in it; the day is the
     * server's today, Asia/Hong_Kong, never the browser's.
     */
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
