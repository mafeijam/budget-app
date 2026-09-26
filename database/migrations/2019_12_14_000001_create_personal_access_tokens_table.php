<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal access tokens, shaped the way laravel/sanctum v4 expects.
 *
 * Laravel 11 stopped auto-loading package migrations, so this table is created
 * here rather than by Sanctum. It therefore has to match what a fresh Sanctum 4
 * install produces, which differs from the 2019 original in two places:
 *
 *   - name        => text  (was string(255))
 *   - expires_at  => indexed, for Sanctum::pruneExpired()
 *
 * The original migration this file replaces was left alone for as long as
 * deployed databases had already run it. The schema history has since been
 * squashed, so the shape Sanctum v4 wants is simply the shape created here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Not optional: Sanctum::pruneExpired() is a full scan without it,
            // and the table is the one place token rows accumulate.
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
