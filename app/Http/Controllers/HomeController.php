<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\AccountBalance;
use App\Support\CardStatement;
use Brick\Math\BigDecimal;

/**
 * Where the money is, and what is owed on the cards: the two questions the home page
 * answers before anyone opens a list.
 */
class HomeController extends Controller
{
    public function index()
    {
        // Every cash account holding money, and every active one whether or not it
        // does. A closed account with a balance still has money in it, and hiding it
        // would make the total a figure the user cannot account for; a closed empty one
        // is just history.
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

        // Every period still owing, on every card, the soonest due first -- inactive
        // cards included, as on the transactions panel: closing a card does not pay it.
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

        return inertia('index', compact('cash', 'statements'));
    }
}
