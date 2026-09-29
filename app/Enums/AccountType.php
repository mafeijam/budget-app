<?php

namespace App\Enums;

enum AccountType: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Security = 'security';

    /**
     * A brokerage holds positions, not money, and its trades' cash is recorded
     * against the bank, so a total of its rows would be a meaningless balance.
     */
    public function hasBalance(): bool
    {
        return match ($this) {
            self::Cash, self::Card => true,

            // Named rather than defaulted, so a new case fails loudly.
            self::Security => false,
        };
    }

    /**
     * The form's pre-filled type, not a rule: the server accepts anything
     * accountTypes() permits.
     */
    public function defaultTransactionType(): TransactionType
    {
        return match ($this) {
            self::Cash => TransactionType::Deposit,
            self::Card => TransactionType::Charge,
            self::Security => TransactionType::Buy,
        };
    }
}
