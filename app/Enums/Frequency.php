<?php

namespace App\Enums;

use Carbon\Carbon;

enum Frequency: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * Counted from the first day rather than from the last occurrence, and without
     * overflow: a payment on the 31st falls on 28 February and is back on 31 March.
     * Stepping from the previous date instead would leave it on the 28th for good.
     */
    public function occurrence(Carbon $first, int $n): Carbon
    {
        return match ($this) {
            self::Monthly => $first->copy()->addMonthsNoOverflow($n),
            self::Yearly => $first->copy()->addYearsNoOverflow($n),
        };
    }
}
