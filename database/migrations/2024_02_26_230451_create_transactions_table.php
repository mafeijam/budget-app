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
 * would stay a column, because MySQL cannot index a JSON path.
 *
 * That line was drawn in both directions while both fields were columns, and it
 * did not hold still. `fx_rate` is a pure attribute and moved to the bag. So did
 * `due_date`, which is a grouping key, against the argument below -- the price
 * being that settling a card is a GROUP BY and the composite index is what makes
 * it cheap. That price is real and the index is gone; the query is
 *
 *   SELECT due_date,
 *          SUM(CASE WHEN type = 'charge'  THEN amount ELSE 0 END)
 *        - SUM(CASE WHEN type = 'payment' THEN amount ELSE 0 END) AS owed
 *     FROM transactions WHERE account_id = ? GROUP BY due_date
 *
 * and it now scans the account's transactions and sorts. Nobody wrote it yet and
 * no row is persisted, so the cost is a comment today. When it is written, the
 * fix is a MySQL generated column projecting the JSON path and an index on that,
 * which buys the fast path without putting the field back where it was.
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

            $table->timestamps();

            // The balance query: always scoped to one account, ordered by date.
            $table->index(['account_id', 'date']);

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
