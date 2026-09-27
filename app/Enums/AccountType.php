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

    /**
     * Whether a balance is a meaningful figure for this type.
     *
     * Cash and card are money, and this app holds every row that moves it. A
     * securities account holds positions rather than money: what the brokerage is
     * worth needs a price this app does not carry, and the cash side of a trade
     * is recorded against the bank it settles through, not here. So a brokerage
     * reports no balance rather than a total of trades, which would read as a
     * position and be one.
     */
    public function hasBalance(): bool
    {
        return match ($this) {
            self::Cash, self::Card => true,

            // Named rather than defaulted to, so a case added to the enum without a
            // decision here fails loudly rather than silently reporting a balance.
            self::Security => false,
        };
    }
}
