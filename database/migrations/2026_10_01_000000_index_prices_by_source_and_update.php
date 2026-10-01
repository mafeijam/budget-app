<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the prices were last fetched, which the home page and the positions page both
     * ask on every visit: max(updated_at) of the yahoo rows. Without this it reads every
     * row of the table to answer.
     */
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->index(['source', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            $table->dropIndex(['source', 'updated_at']);
        });
    }
};
