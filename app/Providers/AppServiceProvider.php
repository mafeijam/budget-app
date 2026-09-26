<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Deliberately does not call Model::unguard(). That made every attribute of
     * every model mass assignable, for the life of every request, so the DTOs
     * handed to create() and update() were writing whatever the payload named --
     * including AccountData::$id and its created_at, which is how a client could
     * renumber a row or backdate it. Each model now carries a $fillable
     * allowlist instead, and MassAssignmentTest asserts that every allowlist
     * matches its table's columns so the two cannot drift apart unnoticed.
     */
    public function boot(): void
    {
        //
    }
}
