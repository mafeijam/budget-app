<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'index');

// Settling a card writes two rows -- a payment on the card and a transfer out of
// the bank it is paid from -- so it is an action on the account rather than a
// transaction of its own. Its own path rather than a resource member, because there
// is no /settle row to show, edit or delete.
Route::post('accounts/{account}/settle', [TransactionController::class, 'settle'])
    ->name('accounts.settle');

Route::resource('accounts', AccountController::class)->except('show', 'edit');
Route::resource('categories', CategoryController::class)->except('show', 'edit');
Route::resource('transactions', TransactionController::class)->except('show', 'edit');
