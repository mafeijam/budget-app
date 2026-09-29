<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transaction that repeats monthly or yearly, written as a pending row on each day it
 * falls due -- see App\Support\RecurringPayments.
 *
 * Its own columns rather than a template's payload, because the recorder reads every
 * field to build a TransactionData, and a key missing from a document would reach a
 * NOT NULL column of transactions as null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();

            // Cascading, as a template does: AccountController only deletes an account
            // with no transactions, so nothing this wrote can be left behind.
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();

            // Restricting, unlike a template: tidying up a category must not quietly stop
            // the rent. CategoryController refuses the delete before the key would.
            $table->foreignId('category_id')->nullable()
                ->constrained('categories')
                ->restrictOnDelete();

            $table->string('type');
            $table->string('description');
            $table->decimal('amount', 12, 4);
            $table->string('ccy');
            $table->decimal('card_amount', 12, 4)->nullable();

            $table->string('frequency');

            // The day and month every occurrence is counted from.
            $table->date('start_date');
            $table->date('end_date')->nullable();

            // The last occurrence written, so a run that repeats writes nothing twice.
            // Null until the first.
            $table->date('last_recorded_on')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_transactions');
    }
};
