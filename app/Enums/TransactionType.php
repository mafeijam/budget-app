<?php

namespace App\Enums;

/**
 * What a transaction row represents.
 *
 * One flat enum because the table is shared by every account type; which values
 * are legal depends on the account, and accountTypes() is where that is defined.
 */
enum TransactionType: string
{
    // Declaration order is the form picker's order.
    case Withdraw = 'withdraw';
    case Deposit = 'deposit';
    case Charge = 'charge';
    case Payment = 'payment';
    case Buy = 'buy';
    case Sell = 'sell';
    case Dividend = 'dividend';

    /**
     * The account types this transaction type is legal on.
     *
     * @return array<int, AccountType>
     */
    public function accountTypes(): array
    {
        return match ($this) {
            self::Withdraw, self::Deposit => [AccountType::Cash],
            self::Charge, self::Payment => [AccountType::Card],
            self::Buy, self::Sell, self::Dividend => [AccountType::Security],
        };
    }

    public function isAllowedFor(AccountType $accountType): bool
    {
        return in_array($accountType, $this->accountTypes(), true);
    }

    /**
     * Which way a row moves its account's balance: 1, -1, or 0.
     *
     * The balance is a position: a card is a liability, so a charge is negative. A
     * brokerage has no balance, so nothing moves it. The opposite sign of
     * CardStatement::owed(), which is a period's debt.
     */
    public function movesBalanceOn(AccountType $accountType): int
    {
        return match ($accountType) {
            AccountType::Cash => match ($this) {
                self::Deposit => 1,
                self::Withdraw => -1,

                // Unreachable, but named rather than defaulted so a new case fails loudly.
                self::Charge, self::Payment, self::Buy, self::Sell, self::Dividend => 0,
            },

            AccountType::Card => match ($this) {
                self::Charge => -1,
                self::Payment => 1,

                self::Withdraw, self::Deposit, self::Buy, self::Sell, self::Dividend => 0,
            },

            AccountType::Security => 0,
        };
    }

    /**
     * Whether the amount is computed from the trade meta rather than supplied.
     */
    public function derivesAmount(): bool
    {
        return in_array($this, [self::Buy, self::Sell], true);
    }

    /**
     * Every case with Deposit first, for the transactions filter. The form keeps
     * declaration order.
     *
     * @return array<int, self>
     */
    public static function filterOrder(): array
    {
        return [self::Deposit, ...array_filter(self::cases(), fn (self $type) => $type !== self::Deposit)];
    }

    /**
     * Whether recording one writes a paired row in the settlement account: a trade
     * or a dividend. Here so the form and TradeCash read the same answer.
     */
    public function needsCashSide(): bool
    {
        return $this->derivesAmount() || $this === self::Dividend;
    }

    /**
     * Only a charge. A withdrawal cannot require one: TradeCash and settle() write
     * withdrawals that have no category to give, and the form would refuse to save them.
     */
    public function requiresCategory(): bool
    {
        return $this === self::Charge;
    }
}
