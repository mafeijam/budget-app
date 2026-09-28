<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PositionController;
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

Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
Route::post('prices', [PositionController::class, 'store'])->name('prices.store');

Route::resource('accounts', AccountController::class)->except('show', 'edit');
Route::resource('categories', CategoryController::class)->except('show', 'edit');
Route::resource('transactions', TransactionController::class)->except('show', 'edit');
