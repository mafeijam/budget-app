<?php

namespace App\Support;

use ArrayObject;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * The billing cycle of one credit card, and the day each cycle falls due.
 *
 * The two numbers mean different things. statement_day is a day of the month and
 * draws the cycle boundaries; the term is a number of days counted forward from
 * the closing day. Both are needed because the term is an interval rather than a
 * calendar day: it says how long after closing, not which day, so on its own it
 * cannot say when the statement it runs from closed.
 */
class CardStatementCycle
{
    public function __construct(
        private readonly int $statementDay,
        private readonly int $termDays,
    ) {
        // Clamping below is for "the 31st does not exist this month", which is
        // a property of the calendar. A day of 0 or 32 is a data entry error,
        // and quietly turning it into the 1st or the 28th would misstate when a
        // card is billed without anything looking wrong. A term outside 1-31 is
        // likewise a data entry error -- zero would make a statement payable on
        // the day it closes, which is not a payment term at all.
        self::guardStatementDay($statementDay);
        self::guardTermDays($termDays);
    }

    /**
     * Build the cycle from an account's meta, or null if it has no card terms.
     *
     * Returns null rather than throwing for a cash or securities account, which
     * have no statement at all, so the caller does not need to branch on the
     * account type.
     *
     * Accepts an ArrayObject as well as an array because that is what Meta casts
     * its JSON column to, so this is what a caller reading $account->meta->meta
     * actually has.
     */
    public static function fromMeta(array|ArrayObject|null $meta): ?self
    {
        if ($meta instanceof ArrayObject) {
            $meta = $meta->getArrayCopy();
        }

        $statementDay = $meta['statement_day'] ?? null;
        $termDays = $meta['term_days'] ?? null;

        if (! is_numeric($statementDay) || ! is_numeric($termDays)) {
            return null;
        }

        return new self((int) $statementDay, (int) $termDays);
    }

    public function statementDay(): int
    {
        return $this->statementDay;
    }

    public function termDays(): int
    {
        return $this->termDays;
    }

    /**
     * The date this charge's statement falls due.
     *
     * Counted from the day the statement closes, not from the charge: a charge
     * made on the 1st on a card closing on the 25th with a 15-day term is due on
     * the 9th of the following month, and the same charge on a card closing on
     * the 2nd is not due until the 17th. Adding days rather than setting a day
     * of the month is also what keeps the term the same length in a short month
     * -- there is no day-of-month to clamp, so a 20-day term stays 20 days in
     * February.
     */
    public function dueDateFor(Carbon $chargeDate): Carbon
    {
        return $this->statementClosingAfter($chargeDate)
            ->addDays($this->termDays);
    }

    /**
     * The first day-of-month on which a statement closes, strictly after the
     * charge.
     *
     * Forwards only, never backwards. A charge can only be billed to a statement
     * that was still open when it was made: a charge on the 20th cannot appear on
     * a statement that cut on the 5th, or the cardholder would be invoiced for a
     * purchase before paying for it.
     *
     * "After", not "on or after", and the closing day is the whole of the
     * difference. An issuer that cuts the statement before that day's activity
     * settles cannot bill a purchase made on the closing day -- the purchase is
     * not on the statement when it is cut -- so a charge made on the 25th of a
     * card closing on the 25th belongs to the statement that closes a month
     * later. It is an issuer convention rather than a law, and it was the other
     * way round here for a while, on the reasoning that issuers cut the statement
     * after the day's activity; that reasoning is what this replaces, so do not
     * put it back without an issuer to point at.
     *
     * A period is therefore the run of days *after* one closing day and up to and
     * including the next: (25 Aug, 25 Sep] for the statement due 10 Oct. It is
     * still a contiguous run with no gaps and no overlap -- only its last day has
     * moved, from the closing day to the one before it. Two charges either side of
     * a boundary still land in different periods, which is the property the whole
     * cycle rests on; test_every_period_is_a_contiguous_run_of_days is what holds
     * it up.
     *
     * The comparison is against the clamped closing day, so a card closing on the
     * 31st has a February statement that closes on the 28th, and a charge on 28
     * February is a boundary charge: it falls after that statement closed and so
     * starts the next one. Same rule, same day the statement actually closes.
     */
    private function statementClosingAfter(Carbon $chargeDate): Carbon
    {
        $thisMonth = $chargeDate->copy()->startOfMonth();

        $closed = $this->dayOfMonth($thisMonth, $this->statementDay);

        if ($closed->lte($chargeDate->copy()->startOfDay())) {
            $closed = $this->dayOfMonth(
                $thisMonth->copy()->addMonthNoOverflow(),
                $this->statementDay
            );
        }

        return $closed;
    }

    /**
     * Set a day of month, pulling it back to the last day when the month is too
     * short to have one.
     *
     * Carbon's day() setter overflows rather than clamping, so a 31st set on
     * February slides into March and moves the whole cycle with it.
     */
    private function dayOfMonth(Carbon $month, int $day): Carbon
    {
        return $month->day(min($day, $month->daysInMonth));
    }

    private static function guardStatementDay(int $day): void
    {
        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException(
                "The statement day must be between 1 and 31, got {$day}."
            );
        }
    }

    private static function guardTermDays(int $days): void
    {
        if ($days < 1 || $days > 31) {
            throw new InvalidArgumentException(
                "The payment term must be between 1 and 31 days, got {$days}."
            );
        }
    }
}
