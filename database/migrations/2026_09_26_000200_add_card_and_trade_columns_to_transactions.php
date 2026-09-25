<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens `transactions` so one table can hold cash, credit card and stock
 * trading rows.
 *
 * The table was shaped for cash alone, which shows up in three places. Only
 * `name`-style uniqueness aside, it required a category, had nowhere to record
 * settlement, and had no lifecycle state -- all of which a card payment and a
 * stock trade cannot supply.
 *
 * A new migration rather than an edit to create_transactions_table: that one
 * has already run, so editing it would leave existing databases on the old
 * shape while a fresh install got the new one. Squashing instead would mean
 * migrate:fresh on the live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // A card payment settles a statement rather than buying anything,
            // and a stock trade is not an expense, so neither can be
            // categorised.
            //
            // No foreign key is dropped or restored here: transactions has no
            // FK constraints at all. create_transactions_table used
            // foreignIdFor(), which since Laravel 11 creates the column only
            // and leaves ->constrained() to the caller. Adding one now would be
            // a silent integrity change -- deleting a referenced category would
            // start failing -- so it is left as a separate decision.
            $table->unsignedBigInteger('category_id')->nullable()->change();

            // Lifecycle state. A card charge is pending until the issuer posts
            // it, and a trade is unsettled until T+2, so `pending` and `settled`
            // both need to be distinguishable and filterable. A status that
            // lives in the JSON meta bag could be neither.
            $table->string('status')->default('posted');

            // Converts `amount`, which is denominated in `ccy`, into the owning
            // account's currency. NULL means "already in the account's
            // currency", i.e. a rate of 1. 16.8 holds a rate such as 7.8495
            // with room to spare.
            $table->decimal('fx_rate', 16, 8)->nullable();

            // The statement cycle a charge rolls up into, and therefore the day
            // that cycle falls due. NULL for every cash, payment, trade and
            // dividend row; the concept is card-specific. A plain date, not a
            // timestamp: a statement falls due on a calendar day, and a time
            // would imply a settlement deadline that does not exist.
            $table->date('due_date')->nullable();

            // The balance query is always scoped to one account and ordered by
            // date. The matching (account_id, due_date) index for the
            // settlement query is deliberately deferred: the table is empty, so
            // it would cost a write on every insert to serve no reads yet.
            $table->index(['account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'date']);
            $table->dropColumn(['status', 'fx_rate', 'due_date']);

            // category_id cannot be made NOT NULL again while uncategorised
            // rows exist, so those are removed rather than left to violate the
            // constraint. Transactions are financial records, so this throws
            // instead of silently deleting anything.
            DB::table('transactions')->whereNull('category_id')->delete();

            $table->unsignedBigInteger('category_id')->nullable(false)->change();
        });
    }
};
