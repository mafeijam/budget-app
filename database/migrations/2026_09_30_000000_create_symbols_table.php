<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a symbol is called, fetched or typed in.
     *
     * A table of its own because a name belongs to the symbol: not to a trade, of which a
     * symbol has many, nor to a day's price. Keyed on the symbol as the prices are, the
     * normalised string Positions::symbol() makes.
     */
    public function up(): void
    {
        Schema::create('symbols', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32)->unique();
            $table->string('name', 120);

            // yahoo, or manual. A manual name is never overwritten by a fetch, as a manual
            // price is not: it exists because the fetched one was missing or too long.
            $table->string('source', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('symbols');
    }
};
