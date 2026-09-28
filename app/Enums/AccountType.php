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

    /**
     * The transaction type to offer first on this account type, or null.
     *
     * A convenience for the form, not a rule: the server accepts anything
     * accountTypes() permits. Cash and card each have an obvious common case, and
     * pre-filling it saves a pick on nearly every transaction.
     *
     * Null for a securities account rather than a guess. Buy and sell are both
     * ordinary there and neither is the usual one, so any default hands the
     * user a type they did not choose -- and the alternative, an empty picker, is
     * something they can already reason about.
     *
     * Here rather than in the template because the pairing of an account type with a
     * transaction type is already this enum's business. A second copy in the browser
     * would be free to drift from accountTypes() without anything noticing.
     */
    public function defaultTransactionType(): ?TransactionType
    {
        return match ($this) {
            self::Cash => TransactionType::Deposit,
            self::Card => TransactionType::Charge,
            self::Security => null,
        };
    }
}
