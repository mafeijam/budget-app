<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen meta.model_id to match the primary keys it points at.
 *
 * accounts.id and categories.id are both created with $table->id(), i.e.
 * unsignedBigInteger. The original create_meta_table migration declared
 * model_id as unsignedInteger, so the column could not address its own parent
 * rows past 4,294,967,295 -- at which point a Meta insert would fail on a
 * primary key that is perfectly valid on the other side of the relation.
 *
 * The type is part of the unique index on (model_id, model_type), so that index
 * is dropped and recreated around the change. Dropping it explicitly keeps the
 * operation deterministic rather than relying on the driver to notice the index
 * and rebuild it implicitly.
 *
 * Widening is lossless: every value already stored in model_id is still
 * representable in the wider type, so this is a no-op for existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta', function (Blueprint $table) {
            $table->dropUnique(['model_id', 'model_type']);
        });

        Schema::table('meta', function (Blueprint $table) {
            $table->unsignedBigInteger('model_id')->change();
        });

        Schema::table('meta', function (Blueprint $table) {
            $table->unique(['model_id', 'model_type']);
        });
    }

    public function down(): void
    {
        Schema::table('meta', function (Blueprint $table) {
            $table->dropUnique(['model_id', 'model_type']);
        });

        Schema::table('meta', function (Blueprint $table) {
            $table->unsignedInteger('model_id')->change();
        });

        Schema::table('meta', function (Blueprint $table) {
            $table->unique(['model_id', 'model_type']);
        });
    }
};
