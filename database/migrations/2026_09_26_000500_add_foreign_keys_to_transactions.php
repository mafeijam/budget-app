<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Checked rather than assumed. A foreign key cannot be added while any
        // row violates it, and MySQL reports that as a failed ALTER TABLE whose
        // message names the constraint rather than the orphan. Saying which row
        // is the problem is the difference between a fixable error and a puzzle.
        //
        // Nothing to check on an empty table, which is the current state of the
        // development database -- but the check is here so that applying this to
        // a populated database fails with something actionable.
        $this->guardNoOrphans('account_id', 'accounts', 'a transaction belongs to an account that no longer exists');
        $this->guardNoOrphans('category_id', 'categories', 'a transaction is filed under a category that no longer exists', notNull: false);

        Schema::table('transactions', function (Blueprint $table) {
            // Every transaction belongs to an account, and an account is
            // meaningless without a type, so this one is not nullable and
            // restrict is right: deleting an account that still has transactions
            // must fail rather than orphan them.
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();

            // Nullable, because a card payment and a stock trade have no
            // category. restrictOnDelete rather than cascade: a category is a
            // label, and deleting one should not silently delete a user's
            // financial history along with it.
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });

        Schema::table('accounts', function (Blueprint $table) {
            // Self-referential. restrict rather than cascade or set null on
            // purpose: a brokerage whose settlement account is deleted becomes a
            // brokerage with no cash story, which is exactly the state
            // AccountData now refuses to create. Cascading would delete a user's
            // securities account because they tidied up a dormant bank account,
            // and setting null would manufacture the invalid row the validation
            // exists to prevent. Failing the delete is the honest outcome.
            $table->foreign('settlement_account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse order: accounts' self-reference is dropped before the
        // transactions constraints, mirroring up().
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['settlement_account_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['category_id']);
        });
    }

    /**
     * Refuse to add a foreign key while rows would violate it.
     */
    private function guardNoOrphans(string $column, string $parent, string $complaint, bool $notNull = true): void
    {
        $query = DB::table('transactions')
            ->whereNull($column)
            ->whereNotExists(function ($q) use ($column, $parent) {
                $q->select(DB::raw(1))
                    ->from($parent)
                    ->whereColumn($parent.'.id', 'transactions.'.$column);
            });

        if (! $notNull) {
            $query = DB::table('transactions')
                ->whereNotNull($column)
                ->whereNotExists(function ($q) use ($column, $parent) {
                    $q->select(DB::raw(1))
                        ->from($parent)
                        ->whereColumn($parent.'.id', 'transactions.'.$column);
                });
        }

        $orphans = $query->limit(5)->pluck('id');

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Cannot add the transactions.%s foreign key: %s. Offending transaction ids: %s. '
                    .'Delete or reassign those rows, or null the column where it is nullable, then migrate again.',
                $column,
                $complaint,
                $orphans->implode(', ')
            ));
        }
    }
};
