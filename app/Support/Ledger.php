<?php

namespace App\Support;

use App\Models\Transaction;

/**
 * Where the reports that look back over the years start: the first whole year.
 *
 * A ledger begun mid-year opens on the balances brought into it -- here, June 2016 -- and its
 * first year is seven months with an opening entry in them. Drawn on the net worth chart and
 * read as a year in review it is noise: a first point that is not a year end, a "since" figure
 * measured from a day nothing happened on, and a year of seven months against whole ones. One
 * rule for every page that counts back, so they start at the same place.
 */
class Ledger
{
    /**
     * The first day the reports count from: 1 January of the first whole year, or the first
     * transaction itself when the ledger began in a January or began this year -- which has no
     * whole year yet, and whose start is the day it began, not a January before it. Null with
     * no transactions.
     */
    public static function start(): ?string
    {
        $first = Transaction::query()->min('date');

        if ($first === null) {
            return null;
        }

        if (substr($first, 5, 2) === '01' || (int) substr($first, 0, 4) >= today()->year) {
            return $first;
        }

        return ((int) substr($first, 0, 4) + 1).'-01-01';
    }
}
