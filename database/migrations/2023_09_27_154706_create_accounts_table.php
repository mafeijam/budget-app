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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('status');
            $table->string('type');
            $table->string('ccy');

            // Where a securities account settles. Nullable because most accounts
            // are not securities accounts -- and because AccountData prohibits
            // the column on anything else, which is what makes a settlement
            // cycle unrepresentable rather than something that has to be
            // detected.
            //
            // A column rather than a meta entry, because it is a relationship:
            // it can be joined and indexed, and a foreign key buried in the JSON
            // bag is awkward to query. meta holds scalar card terms.
            $table->unsignedBigInteger('settlement_account_id')->nullable();

            $table->timestamps();

            // Self-referential. Also the index the foreign key needs, and the
            // lookup for "which brokerages settle into this bank account" --
            // the question a cash account delete has to answer before it may
            // proceed.
            $table->index('settlement_account_id', 'accounts_settlement_account_id_index');

            // Restrict rather than cascade or set null, deliberately. A
            // brokerage whose settlement account is deleted becomes a brokerage
            // with no cash story, which is exactly the state AccountData refuses
            // to create. Cascading would delete a user's securities account
            // because they tidied up a dormant bank account, and setting null
            // would manufacture the invalid row the validation exists to
            // prevent. Failing the delete is the honest outcome.
            $table->foreign('settlement_account_id')
                ->references('id')->on('accounts')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
