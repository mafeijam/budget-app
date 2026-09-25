<?php

namespace App\Enums;

/**
 * The account types the UI offers in FormAccount.vue.
 *
 * This is the single source of truth for the allowed set. Typing the property
 * as AccountType makes spatie/laravel-data reject anything else automatically,
 * so the hardcoded list in the browser can no longer drift ahead of the server.
 */
enum AccountType: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Security = 'security';
}
