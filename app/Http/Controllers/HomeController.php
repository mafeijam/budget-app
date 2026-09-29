<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\AccountBalance;
use App\Support\CardStatement;
use App\Support\Positions;
use Brick\Math\BigDecimal;

class HomeController extends Controller
{
    public function index()
    {
        // A closed account still holding money stays, or the total could not be
        // accounted for.
        $cashAccounts = Account::query()
            ->where('type', AccountType::Cash->value)
            ->orderBy('name')
            ->get();

        $balances = AccountBalance::forAccounts($cashAccounts);

        $cash = $cashAccounts
            ->filter(fn (Account $account) => $account->status === 'active'
                || ! BigDecimal::of($balances[$account->id])->isZero())
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'ccy' => $account->ccy,
                'status' => $account->status,
                'balance' => $balances[$account->id],
            ])
            ->values();

        // Inactive cards included: closing a card does not pay it.
        $statements = Account::query()
            ->where('type', AccountType::Card->value)
            ->with('meta')
            ->orderBy('name')
            ->get()
            ->flatMap(fn (Account $card) => CardStatement::forAccount($card)
                ->reject->isSettled()
                ->map(fn (CardStatement $statement) => [
                    'card' => ['id' => $card->id, 'name' => $card->name, 'ccy' => $card->ccy],
                    ...$statement->toArray(),
                ]))
            ->sortBy('due_date')
            ->values();

        // Beside the cash, not in it: shares at market value are not money.
        $brokerages = Account::query()
            ->where('type', AccountType::Security->value)
            ->orderBy('name')
            ->get()
            ->map(fn (Account $broker) => [
                'id' => $broker->id,
                'name' => $broker->name,
                'ccy' => $broker->ccy,
                'status' => $broker->status,
                ...Positions::valued($broker)['totals'],
            ])
            ->filter(fn (array $broker) => $broker['status'] === 'active' || $broker['open'] > 0)
            ->values();

        return inertia('index', compact('cash', 'brokerages', 'statements'));
    }
}
