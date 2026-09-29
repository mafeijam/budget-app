<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NetWorthController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Settling a card writes two rows -- a payment on the card and a transfer out of
// the bank it is paid from -- so it is an action on the account rather than a
// transaction of its own. Its own path rather than a resource member, because there
// is no /settle row to show, edit or delete.
Route::post('accounts/{account}/settle', [TransactionController::class, 'settle'])
    ->name('accounts.settle');

// Correcting a statement's due date moves one key shared by every charge and payment
// in the period, so it is a property of the period rather than of any row. Its own path
// for the same reason settle() has one: there is no row here to show, edit or delete
// either, and the day it is keyed on travels in the body rather than in the URL.
Route::post('accounts/{account}/due-date', [TransactionController::class, 'moveDueDate'])
    ->name('accounts.due-date');

Route::get('cash-flow', [CashFlowController::class, 'index'])->name('cash-flow');
Route::get('net-worth', [NetWorthController::class, 'index'])->name('net-worth');
Route::get('forecast', [ForecastController::class, 'index'])->name('forecast');
Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
Route::post('prices', [PositionController::class, 'store'])->name('prices.store');
Route::post('prices/fetch', [PositionController::class, 'fetch'])->name('prices.fetch');

// A transaction template is a saved set of the transaction form's values, so it is made
// and used from that form: the list rides along on the transactions page and there is no
// /transaction-templates row to show, edit or index. Only the three verbs the form
// actually calls, which is what makes them worth naming rather than deriving from a
// resource the other half of which would 404.
Route::post('transaction-templates', [TransactionController::class, 'storeTemplate'])
    ->name('templates.store');
Route::put('transaction-templates/{transactionTemplate}', [TransactionController::class, 'updateTemplate'])
    ->name('templates.update');
Route::delete('transaction-templates/{transactionTemplate}', [TransactionController::class, 'destroyTemplate'])
    ->name('templates.destroy');

Route::resource('accounts', AccountController::class)->except('show', 'edit');
Route::resource('categories', CategoryController::class)->except('show', 'edit');
Route::resource('transactions', TransactionController::class)->except('show', 'edit');
// Before the resource, or `run` is read as a rule's id.
Route::post('recurring/run', [RecurringTransactionController::class, 'run'])->name('recurring.run');
// Before the resource for the same reason: `find` would be read as a rule's id.
Route::post('recurring/find', [RecurringTransactionController::class, 'find'])->name('recurring.find');
Route::post('recurring/find/apply', [RecurringTransactionController::class, 'applyFindings'])
    ->name('recurring.find.apply');
Route::resource('recurring', RecurringTransactionController::class)
    ->parameters(['recurring' => 'recurringTransaction'])
    ->except('show', 'edit');
