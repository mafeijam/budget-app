<?php

namespace App\Support;

use ArrayObject;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * statement_day is a day of the month and draws the cycle's boundaries; the term is a
 * number of days counted forward from the closing day.
 */
class CardStatementCycle
{
    public function __construct(
        private readonly int $statementDay,
        private readonly int $termDays,
    ) {
        // Refused rather than clamped: clamping is for a short month, and a day of 0
        // or 32 turned into the 1st or the 28th would misstate the billing silently.
        self::guardStatementDay($statementDay);
        self::guardTermDays($termDays);
    }

    /** Null for an account with no card terms. An ArrayObject is what Meta casts its bag to. */
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

    /** Counted in days from the closing day, so a term keeps its length in February. */
    public function dueDateFor(Carbon $chargeDate): Carbon
    {
        return $this->statementClosingAfter($chargeDate)
            ->addDays($this->termDays);
    }

    /**
     * Strictly after: the statement is cut before the closing day's activity settles,
     * so a charge on the 25th of a card closing on the 25th is billed a month later. An
     * issuer convention, so do not change it without an issuer to point at.
     *
     * Against the clamped day, so a charge on 28 February starts the next period of a
     * card closing on the 31st.
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

    /** Carbon's day() overflows, so a 31st set on February would slide into March. */
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
