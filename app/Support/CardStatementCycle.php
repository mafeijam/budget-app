<?php

namespace App\Support;

use ArrayObject;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * The billing cycle of one credit card, and the day each cycle falls due.
 *
 * Placing a charge in a statement period needs two numbers, not one. A due day
 * on its own cannot do it: many different statement days produce the same due
 * day, so "when is it due" cannot answer "which statement is this in". The
 * statement day draws the cycle boundaries and the due day follows from them.
 *
 * That is why AccountMetaData carries statement_day alongside due. With only
 * `due`, a card closing on the 25th and a card closing on the 5th are
 * indistinguishable, and every charge would be attributed to the wrong
 * statement.
 */
class CardStatementCycle
{
    public function __construct(
        private readonly int $statementDay,
        private readonly int $dueDay,
    ) {
        // Clamping below is for "the 31st does not exist this month", which is
        // a property of the calendar. A day of 0 or 32 is a data entry error,
        // and quietly turning it into the 1st or the 28th would misstate when a
        // card is billed without anything looking wrong.
        self::guardDay($statementDay, 'statement day');
        self::guardDay($dueDay, 'due day');
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
        $dueDay = $meta['due'] ?? null;

        if (! is_numeric($statementDay) || ! is_numeric($dueDay)) {
            return null;
        }

        return new self((int) $statementDay, (int) $dueDay);
    }

    public function statementDay(): int
    {
        return $this->statementDay;
    }

    public function dueDay(): int
    {
        return $this->dueDay;
    }

    /**
     * The date this charge's statement falls due.
     */
    public function dueDateFor(Carbon $chargeDate): Carbon
    {
        $closed = $this->statementClosingOnOrAfter($chargeDate);

        // The due date is the first occurrence of the due day strictly after
        // the statement closed. "Strictly after" is what lets a card that
        // closes early in the month and is due later in that same month work:
        // its due day is ahead of the closing day, so it stays in the closing
        // month instead of being pushed a whole month further out.
        $due = $this->dayOfMonth($closed->copy()->startOfMonth(), $this->dueDay);

        if ($due->lte($closed)) {
            $due = $this->dayOfMonth(
                $closed->copy()->addMonthNoOverflow()->startOfMonth(),
                $this->dueDay
            );
        }

        return $due;
    }

    /**
     * The first day-of-month on which a statement closes, on or after the
     * charge.
     *
     * "On or after", not "before". A charge can only be billed to a statement
     * that was still open when it was made: a charge on the 20th cannot appear
     * on a statement that cut on the 5th, or the cardholder would be invoiced
     * for a purchase before paying for it.
     *
     * A charge made on the closing day itself counts as part of that statement.
     * Issuers cut the statement after that day's activity, so including it is
     * what real issuers do -- and it makes each period a contiguous run that
     * ends on its closing day.
     */
    private function statementClosingOnOrAfter(Carbon $chargeDate): Carbon
    {
        $thisMonth = $chargeDate->copy()->startOfMonth();

        $closed = $this->dayOfMonth($thisMonth, $this->statementDay);

        if ($closed->lt($chargeDate->copy()->startOfDay())) {
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

    private static function guardDay(int $day, string $label): void
    {
        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException(
                "The {$label} must be between 1 and 31, got {$day}."
            );
        }
    }
}
