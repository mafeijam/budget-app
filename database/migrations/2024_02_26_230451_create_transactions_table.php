<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table for cash, credit card and stock trading rows.
 *
 * The three kinds share a table deliberately: a purchase from a savings account
 * and a purchase on a card are the same event seen from two sides, and splitting
 * them would mean every balance query unioning three shapes.
 *
 * What that costs is that several columns are only meaningful for some rows, so
 * nullability here is load-bearing. A card payment settles a statement and a
 * stock trade is not an expense, so a category on either is optional rather
 * than impossible. A trade's amount is derived from its meta rather than
 * supplied, and only a charge carries a due date. Getting any of those wrong
 * does not fail loudly -- it either rejects a valid row or silently admits an
 * invalid one -- which is why TransactionSchemaTest asserts each of them.
 *
 * `amount` is a positive magnitude. Its direction comes from the account type
 * and the transaction type, so an expense and a payment are told apart by what
 * they are, not by a sign that could be entered the wrong way round.
 *
 * What is a column and what is in the meta bag is not decided by how often a
 * field is used but by what it is asked to do. An attribute you display goes in
 * the bag, so that adding one needs no migration. A key you group and filter on
 * stays a column, because MySQL cannot index a JSON path and the settlement
 * query below is a GROUP BY. `fx_rate` moved to the bag on that first count and
 * `due_date` is held back on the second; the ceiling the fx_rate column used to
 * impose is now a rule in TransactionMetaData, since a bag is not what refuses
 * an over-wide number.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // Restrict on delete for both. A transaction cannot exist without an
            // account. A category is only a label, so cascading there would
            // delete a user's financial history because they tidied up a
            // category; refusing the delete is the only coherent outcome.
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            // Nullable, and optional rather than forbidden. A stock trade has
            // nothing to categorise, and a card payment settles a statement
            // rather than buying anything -- but a payment may still be labelled
            // where one payment covers several purchases. Only the two
            // categorised-spending types require it, and that is a rule, not a
            // schema constraint.
            $table->foreignId('category_id')->nullable()
                ->constrained('categories')
                ->restrictOnDelete();

            // A positive magnitude, not a signed one. See the class docblock.
            $table->decimal('amount', 12, 4);

            $table->string('type');
            $table->string('description');
            $table->string('ccy');
            $table->date('date');

            // Lifecycle state. A card charge is pending until the issuer posts
            // it, and a trade is unsettled until T+2, so `pending` and `settled`
            // both need to be distinguishable and filterable. A status living in
            // the JSON meta bag could be neither, and `posted` is the right
            // default for the cash rows that predate the concept.
            $table->string('status')->default('posted');

            // The statement cycle a charge rolls up into, and therefore the day
            // that cycle falls due. NULL for every cash, payment, trade and
            // dividend row; the concept is card-specific. A plain date, not a
            // timestamp: a statement falls due on a calendar day, and a time
            // would imply a settlement deadline that does not exist.
            //
            // A column and not a meta entry, unlike the trade fields beside it,
            // because it is a grouping key rather than an attribute: settling a
            // card is a GROUP BY over it, which is what the index below is for,
            // and MySQL cannot index a JSON path. The reversal that took fx_rate
            // out of this table and the argument that put due_date's predecessor
            // in it are both in the class docblock.
            $table->date('due_date')->nullable();

            $table->timestamps();

            // The balance query: always scoped to one account, ordered by date.
            $table->index(['account_id', 'date']);

            // The settlement query:
            //
            //   SELECT due_date,
            //          SUM(CASE WHEN type = 'charge'  THEN amount ELSE 0 END)
            //        - SUM(CASE WHEN type = 'payment' THEN amount ELSE 0 END) AS owed
            //     FROM transactions WHERE account_id = ? GROUP BY due_date
            //
            // Leading with account_id keeps it scoped; trailing due_date lets
            // MySQL take the groups off the index in order instead of sorting
            // them.
            $table->index(['account_id', 'due_date'], 'transactions_account_due_index');

            // The category_id foreign key needs one, and independently it is the
            // lookup behind "everything filed under this category" -- the
            // question a category delete has to answer before it may claim the
            // category is unused.
            $table->index('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
