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
            self::Withdraw, self::Deposit, self::Dividend => [AccountType::Cash],
            self::Charge, self::Payment => [AccountType::Card],
            self::Buy, self::Sell => [AccountType::Security],
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
                self::Deposit, self::Dividend => 1,
                self::Withdraw => -1,

                // Unreachable, but named rather than defaulted so a new case fails loudly.
                self::Charge, self::Payment, self::Buy, self::Sell => 0,
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
     * The order types are offered in, wherever they are: the form's type buttons and the
     * list's filter. Deposit first, as money arrives before it is spent; the rest as declared.
     *
     * @return array<int, self>
     */
    public static function offeredOrder(): array
    {
        return [self::Deposit, ...array_filter(self::cases(), fn (self $type) => $type !== self::Deposit)];
    }

    /** A trade, or a dividend naming the holding that paid it. */
    public function carriesSymbol(): bool
    {
        return $this->derivesAmount() || $this === self::Dividend;
    }

    /** Not a trade, whose amount is the day's price, nor a dividend, which varies. */
    public function canRecur(): bool
    {
        return ! $this->carriesSymbol();
    }

    /** Not a withdrawal: TradeCash and settle() write withdrawals with no category. */
    public function requiresCategory(): bool
    {
        return $this === self::Charge;
    }
}
