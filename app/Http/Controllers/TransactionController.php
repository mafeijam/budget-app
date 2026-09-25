<?php

namespace App\Http\Controllers;

use App\DTO\TransactionData;
use App\Models\Account;
use App\Models\Category;

class TransactionController extends Controller
{
    public function index()
    {
        $formEmpty = TransactionData::empty();

        $meta = [
            'form' => 'transaction-form',
            'path' => '/transactions',
        ];

        $accounts = Account::query()
            ->with('meta')
            ->where('status', 'active')->get()->map(fn ($account) => [
                // ...$account->toArray(),
                'label' => $account->name,
                'value' => $account->id,
            ]);

        $categories = Category::all()->map(fn ($category) => [
            // ...$category->toArray(),
            'label' => $category->name,
            'value' => $category->id,
        ]);

        $options = compact('accounts', 'categories');

        return inertia('transaction', compact('formEmpty', 'meta', 'options'));
    }

    public function store()
    {
        return TransactionData::getValidationRules(request()->all());
    }
}
