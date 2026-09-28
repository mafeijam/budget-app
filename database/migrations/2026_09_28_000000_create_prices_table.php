<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A symbol's closing price on a day, fetched or typed in.
     *
     * A table rather than a cache, so a price persists, a failed fetch can be retried
     * rather than remembered as a failure for a week, and a user can correct one. One
     * row per symbol per day: a symbol trades in one currency on one exchange, so the
     * pair is the key, and fetching a day twice updates it rather than adding a second.
     */
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32);
            $table->date('date');

            // Four places, the scale amount and unit_price use; the same eight integer
            // digits leave room for any share price.
            $table->decimal('close', 12, 4);
            $table->char('ccy', 3);

            // Where it came from: yahoo, or manual. A manual price is never overwritten
            // by a fetch -- it exists because the fetched one was missing or wrong.
            $table->string('source', 16);
            $table->timestamps();

            $table->unique(['symbol', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
