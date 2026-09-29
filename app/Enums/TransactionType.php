<?php

namespace App\Enums;

/** One flat enum for every account type; accountTypes() says which are legal where. */
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
     * 1, -1 or 0. A balance is a position, so a card charge is negative: the
     * opposite sign of CardStatement::owed(), which is a period's debt.
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

    public function derivesAmount(): bool
    {
        return in_array($this, [self::Buy, self::Sell], true);
    }

    /**
     * @return array<int, self>
     */
    public static function filterOrder(): array
    {
        return [self::Deposit, ...array_filter(self::cases(), fn (self $type) => $type !== self::Deposit)];
    }

    /** Whether recording one writes a paired row in the settlement account. */
    public function needsCashSide(): bool
    {
        return $this->derivesAmount() || $this === self::Dividend;
    }

    /**
     * A trade's amount is decided by the day's price, and a dividend's cash side by what
     * is held, so neither can be written ahead from a fixed figure.
     */
    public function canRecur(): bool
    {
        return ! $this->needsCashSide();
    }

    /** Not a withdrawal: TradeCash and settle() write withdrawals with no category. */
    public function requiresCategory(): bool
    {
        return $this === self::Charge;
    }
}
