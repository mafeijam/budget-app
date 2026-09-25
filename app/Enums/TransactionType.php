<?php

namespace App\Enums;

/**
 * What a transaction row represents.
 *
 * This is deliberately a single flat enum rather than three, because the
 * `transactions` table is shared by all three account types. What makes a value
 * legal is the owning account's type, not the value on its own: a `payment`
 * only means anything on a credit card, and a `sell` only on a securities
 * account. accountTypes() is the single place that pairing is defined, and
 * TransactionData validates against it.
 *
 * `amount` is a client-supplied value for every type except the two trades.
 * A trade's amount is quantity x unit price, so it is derived on the server
 * and a client-supplied amount is rejected outright -- see derivesAmount().
 */
enum TransactionType: string
{
    // Cash accounts. The simple case: money in, money out.
    case Expense = 'expense';
    case Income = 'income';

    // Credit card accounts. A charge spends the card's credit and rolls up into
    // a statement period; a payment reduces what is owed and is deliberately
    // not an expense, so it carries no category.
    case Charge = 'charge';
    case Payment = 'payment';

    // Stock trading accounts. A buy and a sell are trades whose amount is
    // derived; a dividend is simply income and is client-supplied.
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
            self::Expense, self::Income => [AccountType::Cash],
            self::Charge, self::Payment => [AccountType::Card],
            self::Buy, self::Sell, self::Dividend => [AccountType::Security],
        };
    }

    public function isAllowedFor(AccountType $accountType): bool
    {
        return in_array($accountType, $this->accountTypes(), true);
    }

    /**
     * Whether the amount is computed from the trade meta rather than supplied.
     *
     * A dividend is not a trade: it arrives as a fixed cash amount with no
     * quantity or unit price, so it is client-supplied like the other types.
     */
    public function derivesAmount(): bool
    {
        return in_array($this, [self::Buy, self::Sell], true);
    }

    /**
     * Whether a category is mandatory.
     *
     * Only a genuine expense needs one. A payment settles a statement, and a
     * trade or dividend is not categorised spending. Income is optional too,
     * because `categories` has no income/expense discriminator to select from --
     * see the note in TransactionData.
     */
    public function requiresCategory(): bool
    {
        return in_array($this, [self::Expense, self::Charge], true);
    }
}
