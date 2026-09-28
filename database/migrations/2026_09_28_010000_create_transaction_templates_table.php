<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved set of the transaction form's values, so a transaction that repeats can be
 * filled in again instead of retyped.
 *
 * A template is not a transaction: it holds no amount of its own in any account, and
 * nothing sums it. What it stores is the form's state at the moment it was saved, which
 * is why the payload is JSON and why the two ids are not in it.
 *
 * The ids are columns, and both of them cascade, for two reasons that pull the same way.
 * The picker groups templates by account, and a key you group on cannot live in the
 * payload -- see create_transactions_table for what MySQL cannot index there. And an
 * account or a category that is deleted takes its templates with it, which a foreign key
 * does and a key in the payload could not: the alternative is a template naming something
 * that no longer exists, filling a field the form cannot show, and refusing the save with
 * an error about a field the user has no way to clear.
 *
 * Cascading rather than restricting, which is the opposite of the transactions table's
 * choice and for the opposite reason. A transaction is history; deleting it because its
 * label went away would delete a record of money, so that table refuses. A template is a
 * shortcut, and losing one costs nothing that is not still in the form. An account delete
 * only ever reaches an account with no transactions -- AccountController refuses the rest
 * -- so the cascade cannot take a template that was standing in for history.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transaction_templates', function (Blueprint $table) {
            $table->id();

            // Unique because the picker shows names and nothing else, so two templates
            // called "Coffee" would be indistinguishable. What the server does with a
            // name already in use is append a number rather than refuse the save; the
            // index is what makes "netflix" and "Netflix" the same name, under the
            // column's collation, rather than two near-identical rows in a short list.
            $table->string('name')->unique();

            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();

            // Nullable, as most transaction types ask for no category: an expense and a
            // charge need one and nothing else does. A cascade off a null column touches
            // nothing, so a template with no category survives any deletion.
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->cascadeOnDelete();

            // The form's own values as they stood when the template was saved. Which
            // keys survive is not decided here: a payload is whatever the browser sent,
            // and the keys a template may keep are listed once in
            // TransactionTemplateData::keeps(), where a server-owned key can be excluded
            // by name rather than trusted to be absent.
            $table->json('payload');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_templates');
    }
};
