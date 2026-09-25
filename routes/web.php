<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'index');

Route::resource('accounts', AccountController::class)->except('show', 'edit');
Route::resource('categories', CategoryController::class)->except('show', 'edit');
Route::resource('transactions', TransactionController::class)->except('show', 'edit');
