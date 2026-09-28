<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\Positions;
use Brick\Math\BigDecimal;

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
                $positions = array_values(Positions::forAccount($broker));
                $open = array_values(array_filter($positions, fn (array $p) => $p['open']));

                // Each figure is in this brokerage's currency, which is every trade's, so
                // summing within one is summing like with like; nothing sums across two.
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
                    'open_cost' => $sum(array_column($open, 'cost')),
                    'realised' => $sum(array_column($positions, 'realised')),
                    'fees' => $sum(array_column($positions, 'fees')),
                    'dividends' => $sum(($dividends[$broker->id] ?? collect())->pluck('amount')),
                ];
            })
            // A closed brokerage with nothing in it is history; one still holding shares is not.
            ->filter(fn (array $b) => $b['status'] === 'active' || collect($b['positions'])->contains('open', true))
            ->values();

        return inertia('position', compact('brokerages'));
    }
}
