<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns the personal_access_tokens table with the schema shipped by
 * laravel/sanctum v4.
 *
 * Laravel 11 stopped auto-loading package migrations, and Sanctum 4 expects:
 *   - name        => text  (was string(255))
 *   - expires_at  => indexed, for Sanctum::pruneExpired()
 *
 * The original 2019_12_14_000001 migration has already run on every existing
 * database, so it is deliberately left untouched; this additive migration
 * brings deployed schemas in line with what a fresh Sanctum 4 install creates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->text('name')->change();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->string('name')->change();
        });
    }
};
