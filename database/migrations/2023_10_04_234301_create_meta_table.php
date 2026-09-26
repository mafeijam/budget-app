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
        Schema::create('meta', function (Blueprint $table) {
            $table->id();

            // Type-specific attributes, stored as JSON rather than as columns so
            // adding a field needs no migration. AccountMetaData and
            // TransactionMetaData describe what may appear here; the database
            // deliberately does not, or every new field would still be a
            // migration.
            $table->json('meta');

            // bigint, to match the $table->id() primary keys this points at.
            // An unsignedInteger here cannot address its own parent rows past
            // 4,294,967,295, at which point a Meta insert fails on a primary key
            // that is perfectly valid on the other side of the relation.
            //
            // Polymorphic, so no foreign key: the same pair addresses an Account
            // or a Category, and MySQL cannot express "one of these two tables"
            // as a constraint. The composite unique is what keeps it to one row.
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');

            $table->timestamps();

            $table->unique(['model_id', 'model_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meta');
    }
};
