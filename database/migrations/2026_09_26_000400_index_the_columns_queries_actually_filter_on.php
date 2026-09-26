<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // The settlement query: how much is owed on a card statement, which
            // is a GROUP BY due_date scoped to one account. Leading with
            // account_id keeps it scoped, and the trailing due_date lets MySQL
            // read the groups off the index rather than sorting them.
            //
            // due_date rather than a statement id: it is the period key, so no
            // statements table is needed for a card to be settled.
            $table->index(['account_id', 'due_date'], 'transactions_account_due_index');

            // Required by the category_id foreign key in the next migration, and
            // independently the lookup for "everything filed under this
            // category", which is what a category rename or a delete has to
            // check before it can claim the category is unused.
            $table->index('category_id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            // Required by the settlement_account_id foreign key, and the lookup
            // for "which brokerages settle into this bank account" -- the
            // question a cash account delete has to answer before it may
            // proceed.
            $table->index('settlement_account_id', 'accounts_settlement_account_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Transactions first, then accounts, mirroring up(). Not a style choice:
        // the accounts drop failed on the first attempt because dropIndex() was
        // given a bare column name where the index had been created under an
        // explicit name, and MySQL aborted the whole rollback at that point --
        // after the transactions indexes were already gone and before the
        // accounts one was dropped. Dropping in reverse order means a failure
        // partway through leaves the earlier statements still applied and
        // visible, rather than a half-reverted schema nobody can reason about.
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_account_due_index');
            $table->dropIndex('transactions_category_id_index');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex('accounts_settlement_account_id_index');
        });
    }
};
