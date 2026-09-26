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
        Schema::table('accounts', function (Blueprint $table) {
            // Where a securities account settles. Nullable because most accounts
            // are not securities accounts -- and because AccountData prohibits the
            // column on anything else, which is what makes a settlement cycle
            // unrepresentable rather than something that has to be detected.
            $table->unsignedBigInteger('settlement_account_id')->nullable()->after('ccy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('settlement_account_id');
        });
    }
};
