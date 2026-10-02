<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ApproveController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DividendController;
use App\Http\Controllers\EnrolController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\FormContextController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\NetWorthController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\YearReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:20,1');
});

// Open to a signed-in device too: enrolling again only signs it in again.
Route::get('enrol/{token}', [EnrolController::class, 'show'])->name('enrol');
Route::post('enrol/{token}', [EnrolController::class, 'store'])->middleware('throttle:10,1');

Route::get('approve/{token}', [ApproveController::class, 'show'])->name('approve');

// auth.session ends every session and remember cookie when the password changes, which is how
// login:revoke signs out a lost phone.
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('approve/{token}', [ApproveController::class, 'update'])->middleware('throttle:20,1');

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
    // JSON for the page's quick view, which opens a category's rows without leaving it.
    Route::get('cash-flow/transactions', [CashFlowController::class, 'transactions'])->name('cash-flow.transactions');
    Route::get('net-worth', [NetWorthController::class, 'index'])->name('net-worth');
    Route::get('forecast', [ForecastController::class, 'index'])->name('forecast');
    Route::get('review', [YearReviewController::class, 'index'])->name('review');
    // A form's props alone, for the Add menu to open it over any page.
    Route::get('forms/{form}', [FormContextController::class, 'show'])->name('forms.show');
    Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
    // JSON for the page's quick view of the dividends one line has received.
    Route::get('positions/dividends', [PositionController::class, 'dividends'])->name('positions.dividends');
    Route::get('dividends', [DividendController::class, 'index'])->name('dividends');
    Route::post('prices', [PositionController::class, 'store'])->name('prices.store');
    Route::post('prices/fetch', [PositionController::class, 'fetch'])->name('prices.fetch');
    Route::post('symbols/name', [PositionController::class, 'name'])->name('symbols.name');

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
    // Two rows written as one: there is no transfer row of its own to show or delete, and either
    // half names the pair to rewrite.
    Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
    Route::put('transfers/{transaction}', [TransferController::class, 'update'])->name('transfers.update');
    Route::resource('transactions', TransactionController::class)->except('show', 'edit');
    // Before the resource, or `run` is read as a rule's id.
    Route::post('recurring/run', [RecurringTransactionController::class, 'run'])->name('recurring.run');
    // Before the resource for the same reason: `find` would be read as a rule's id.
    Route::post('recurring/find', [RecurringTransactionController::class, 'find'])->name('recurring.find');
    Route::post('recurring/{recurringTransaction}/adopt', [RecurringTransactionController::class, 'adopt'])
        ->name('recurring.adopt');
    Route::post('recurring/find/apply', [RecurringTransactionController::class, 'applyFindings'])
        ->name('recurring.find.apply');
    Route::resource('recurring', RecurringTransactionController::class)
        ->parameters(['recurring' => 'recurringTransaction'])
        ->except('show', 'edit');
});
