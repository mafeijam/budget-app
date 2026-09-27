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

    // Money leaving a bank toward something whose far side this app does not track
    // -- most often a credit card payment, which records two rows: a Payment on the
    // card and a Transfer on the bank.
    //
    // Not an expense, and that is the whole reason it is a case of its own. Expense
    // requires a category and counts as spending, so recording a card repayment as
    // one would put money that was never spent into every spending total. Cash only:
    // the card side of the pair already has a type, and a transfer here would be a
    // second name for it.
    case Transfer = 'transfer';

    // Credit card accounts. A charge spends the card's credit and rolls up into
    // a statement period; a payment reduces what is owed and is deliberately
    // not an expense, so it need not be categorised -- though a payment may
    // still be labelled, which is useful when one payment covers several
    // purchases or is a reimbursement of a specific one.
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
            self::Expense, self::Income, self::Transfer => [AccountType::Cash],
            self::Charge, self::Payment => [AccountType::Card],
            self::Buy, self::Sell, self::Dividend => [AccountType::Security],
        };
    }

    public function isAllowedFor(AccountType $accountType): bool
    {
        return in_array($accountType, $this->accountTypes(), true);
    }

    /**
     * Which way a row of this type moves its account's balance: 1, -1, or 0.
     *
     * The account type is a parameter because `amount` is a positive magnitude and
     * its direction comes from the pair. On a cash account an expense is money
     * leaving; on a card a charge is money owed to an issuer, which is the same
     * direction of travel as a payment on a bank, so neither the type nor the
     * account alone settles the sign.
     *
     * Zero means the row does not count, which is the answer for a securities
     * account because it has no balance to move.
     */
    public function movesBalanceOn(AccountType $accountType): int
    {
        return match ($accountType) {
            AccountType::Cash => match ($this) {
                self::Income => 1,
                self::Expense, self::Transfer => -1,

                // Unreachable -- accountTypes() permits none of these on a cash
                // account -- but named rather than defaulted, so a case added to
                // the enum without a decision here fails loudly.
                self::Charge, self::Payment, self::Buy, self::Sell, self::Dividend => 0,
            },

            AccountType::Card => match ($this) {
                self::Charge => 1,
                self::Payment => -1,

                self::Expense, self::Income, self::Transfer, self::Buy, self::Sell, self::Dividend => 0,
            },

            AccountType::Security => 0,
        };
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
     * Only a genuine expense needs one. A payment settles a statement rather
     * than buying anything, so it is optional rather than forbidden: the
     * settlement arithmetic is a plain SUM over charge and payment rows and never
     * looks at category_id, so a label costs nothing and is worth having when
     * one payment covers several purchases. A trade or dividend is not
     * categorised spending.
     *
     * Income is optional too, because `categories` has no income/expense
     * discriminator to select from -- see the note in TransactionData.
     */
    public function requiresCategory(): bool
    {
        return in_array($this, [self::Expense, self::Charge], true);
    }
}
