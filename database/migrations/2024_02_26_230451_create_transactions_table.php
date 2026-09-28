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
 * did not hold still. `fx_rate` was a pure attribute and moved to the bag, then
 * was removed outright once card_amount made it redundant -- a rate converts
 * nothing, and a stated figure is the figure someone meant. So did
 * `due_date`, which is a grouping key, against the argument below -- the price
 * being that settling a card is a GROUP BY and the composite index is what makes
 * it cheap. That price is real, the index is gone, and it is now paid rather than
 * predicted: App\Support\CardStatement is that query.
 *
 * Measured on 180 transactions over 20 periods, the plan it gets is worse than
 * "scans the account's transactions", which is what an earlier version of this
 * comment claimed. The optimizer drives from `meta` and does a full table scan of
 * it -- type ALL, key NULL -- once per card, because the JSON path is unindexable
 * and every bag in the database becomes a candidate. Since `meta` grows in step
 * with `transactions`, that choice does not improve with scale.
 *
 * The way out, also measured, and the first attempt at it does not work. A
 * generated column cannot contain a subquery, so projecting the path onto
 * `transactions` is refused outright (ERROR 3102). The column has to go on `meta`,
 * which already holds the JSON:
 *
 *   ALTER TABLE meta
 *     ADD COLUMN due_date varchar(10)
 *       GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(meta, '$.due_date'))) STORED,
 *     ADD INDEX meta_model_due_index (model_type, model_id, due_date);
 *
 * which turns the plan into type ref on that index with "Using index" -- a
 * covering read, no table lookups. The cheaper alternative is the column with no
 * new index plus a STRAIGHT_JOIN, which drives from transactions_account_id_date_index
 * instead and looks `meta` up by its existing (model_id, model_type) unique. Both
 * read this account's rows; only the index gets the optimizer there on its own.
 *
 * None of that is done here, because doing it is a decision about how many cards
 * and how much history this app is expected to hold, and none of that is known
 * yet. It is recorded here rather than left to be rediscovered.
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

            // Six values, and what makes one legal here is the owning account's type
            // rather than the value on its own: a payment only means anything on a card,
            // a sell only on a brokerage. See TransactionType::accountTypes(), which is
            // the single place that pairing is defined.
            //
            //   cash       withdraw, deposit
            //   card       charge, payment
            //   securities buy, sell, deposit (a dividend)
            //
            // Plain strings rather than an enum column, so a value this app does not know
            // is stored rather than refused -- and read back through TransactionType::from()
            // in TransactionData::fromModel(), which throws on one it does not know. That
            // makes an unfamiliar value a 500 on the transactions page rather than a row
            // that renders, which is the reason a change to the vocabulary here needs a
            // data migration against any database that already holds rows.
            $table->string('type');
            $table->string('description');
            $table->string('ccy');
            $table->date('date');

            // Lifecycle state. A card charge is pending until the issuer posts
            // it, and a trade is pending until it settles, so `pending` has to
            // be distinguishable and filterable -- it is the one state a balance
            // leaves out. A status living in the JSON meta bag could be neither,
            // and `posted` is the right default for the cash rows that predate
            // the concept.
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
