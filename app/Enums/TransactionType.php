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
 * Six cases, and the reduction from nine was a correction rather than a tidy-up.
 * A bank account had four types for two directions of travel: expense and
 * transfer were both money leaving, income and deposit both money arriving, and
 * nothing in the balance arithmetic could tell the pairs apart -- AccountBalance's
 * CASE reads the type to get a sign and the amount, so income and deposit were
 * the same row with two names. What was lost by collapsing them was a distinction
 * the app could not act on: expense was distinguished from transfer so that
 * paying a credit card would not count as spending, which mattered when a card
 * payment was its own type and a spending total existed to be wrong in. It does
 * not now -- a withdrawal is categorised or it is not, and the categories page
 * reads the category rather than the type. So withdraw and deposit say what
 * happened to the account, and the description says what for.
 *
 * `amount` is a client-supplied value for every type except the two trades.
 * A trade's amount is quantity x unit price, so it is derived on the server
 * and a client-supplied amount is rejected outright -- see derivesAmount().
 */
enum TransactionType: string
{
    // Cash accounts. Money in, money out, and that is the whole distinction:
    // a salary and the proceeds of a share sale both arrive, and a shop
    // purchase and a credit card repayment both leave.
    case Withdraw = 'withdraw';

    // Credit card accounts. A charge spends the card's credit and rolls up into
    // a statement period; a payment reduces what is owed and is deliberately
    // not a withdrawal, so it need not be categorised -- though a payment may
    // still be labelled, which is useful when one payment covers several
    // purchases or is a reimbursement of a specific one.
    case Charge = 'charge';
    case Payment = 'payment';

    // Stock trading accounts. A buy and a sell are trades whose amount is
    // derived.
    case Buy = 'buy';
    case Sell = 'sell';

    // Money arriving, on either kind of account -- which is why it is declared
    // last rather than beside Withdraw, with which it pairs.
    //
    // The declaration order is the order the picker offers, because cases() is walked
    // in declaration order to build the list. Declared second, this would put a
    // deposit ahead of both trades on a brokerage, where buy and sell are what a user
    // reaches for and a dividend is an occasional thing. Last, every account's own
    // list reads the way a person would expect: a bank is offered a withdrawal then a
    // deposit, a brokerage a buy, a sell, then a deposit.
    case Deposit = 'deposit';

    /**
     * The account types this transaction type is legal on.
     *
     * @return array<int, AccountType>
     */
    public function accountTypes(): array
    {
        return match ($this) {
            self::Withdraw => [AccountType::Cash],
            self::Deposit => [AccountType::Cash, AccountType::Security],
            self::Charge, self::Payment => [AccountType::Card],
            self::Buy, self::Sell => [AccountType::Security],
        };
    }

    public function isAllowedFor(AccountType $accountType): bool
    {
        return in_array($accountType, $this->accountTypes(), true);
    }

    /**
     * Which way a row of this type moves its account's balance: 1, -1, or 0.
     *
     * The balance is the account's position, not the direction cash travels, and
     * that is the whole of the sign: a cash account holds what is in it, and a card
     * is a liability, so a charge is negative and a payment positive. A card paid
     * beyond its charges therefore reads positive -- the card owes the user.
     *
     * A charge and a payment on a bank are opposites here and alike physically,
     * which is why the account type is a parameter: `amount` is a positive
     * magnitude, so it is the pair that says which way.
     *
     * Zero means the row does not count, the answer for a securities account
     * because it has no balance to move. That is also why a deposit is legal on
     * one: a dividend recorded there moves nothing, and is read by
     * PositionController for the income it was rather than by any balance.
     *
     * The opposite of CardStatement::owed(), which is a period's debt and stays
     * positive. The statement query carries its own CASE and does not read this.
     */
    public function movesBalanceOn(AccountType $accountType): int
    {
        return match ($accountType) {
            AccountType::Cash => match ($this) {
                self::Deposit => 1,
                self::Withdraw => -1,

                // Unreachable -- accountTypes() permits none of these on a cash
                // account -- but named rather than defaulted, so a case added to
                // the enum without a decision here fails loudly.
                self::Charge, self::Payment, self::Buy, self::Sell => 0,
            },

            AccountType::Card => match ($this) {
                self::Charge => -1,
                self::Payment => 1,

                self::Withdraw, self::Deposit, self::Buy, self::Sell => 0,
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
     * Every case, in the order the transactions filter offers them.
     *
     * Not the declaration order, which belongs to the form: that list decides what may
     * be recorded on an account, and it reads the way a person expects of the account
     * they are on -- a withdrawal before a deposit on a bank, a buy and a sell before a
     * dividend on a brokerage. The filter narrows a list that already exists rather
     * than offering the choices, so it wants the type a person reaches for first and
     * not the order the types pair with account types in.
     *
     * A deposit leads, being the one type accountTypes() allows on more than one kind of
     * account. Lifting it out of the sequence leaves what follows grouped by the account
     * type each belongs to -- cash, then card, then security -- which is a grouping the
     * declaration order happens to have anyway.
     *
     * @return array<int, self>
     */
    public static function filterOrder(): array
    {
        // sortBy rather than an array_filter of the rest: it leaves the remaining cases
        // in declaration order by itself, and PHP's sort being stable is what makes that
        // true rather than a second thing to keep in step.
        return collect(self::cases())
            ->sortBy(fn (self $type) => $type === self::Deposit ? 0 : 1)
            ->values()
            ->all();
    }

    /**
     * Whether recording one of these writes a row in the settlement account.
     *
     * A trade, and a deposit on a brokerage -- which is a dividend, and is money
     * arriving rather than money already sitting in a bank being counted a second time.
     * Both pay into the brokerage's settlement account, so both are written as a pair.
     *
     * The account type is a parameter for the reason accountTypes() takes one: a deposit
     * is money in on either kind of account, and only a brokerage's has a bank behind it.
     *
     * Here rather than in TradeCash, which is the other caller: the form needs the same
     * answer per account type to know which fields to show, and a second copy of it in
     * the browser would be free to drift from the first -- a field shown for a type whose
     * cash side is not written, or hidden for one whose is.
     */
    public function needsCashSide(AccountType $accountType): bool
    {
        return $this->derivesAmount() || $this->isDividend($accountType);
    }

    /**
     * Whether a row of this type is a dividend: money received on a holding already owned.
     *
     * A deposit on a brokerage, and a deposit nowhere else. The account type is a parameter
     * for the reason accountTypes() takes one -- a deposit is money in on either kind of
     * account, and only a brokerage's has shares behind it.
     *
     * A name for the thing, rather than left to each caller to spot. Three places had it
     * written out as `type === Deposit`, and each of them was reaching it to choose a noun
     * for a message -- "Delete the dividend instead, and its cash goes with it" -- so the
     * failure of a fourth copy would be a user told to edit a trade on a row that is a
     * deposit, and sent to a picker holding only buys and sells.
     */
    public function isDividend(AccountType $accountType): bool
    {
        return $this === self::Deposit && $accountType === AccountType::Security;
    }

    /**
     * Whether a category is mandatory.
     *
     * A charge is money spent and is labelled. A withdrawal is not required to be, and
     * cannot be: TradeCash writes one beside every buy and TransactionController::settle()
     * writes one beside every card payment, and neither is a purchase with a category to
     * give. Requiring one would make the app's own rows unsaveable in the form -- the edit
     * refused over a category the user cannot supply and did not choose to leave off.
     *
     * So a label is available and optional, as on a payment: the settlement arithmetic is
     * a plain SUM over charge and payment rows and never looks at category_id, so a label
     * costs nothing and is worth having on a withdrawal that really was spending.
     *
     * A deposit is optional for a second reason: `categories` has no income/expense
     * discriminator to select from -- see the note in TransactionData.
     */
    public function requiresCategory(): bool
    {
        return $this === self::Charge;
    }
}
