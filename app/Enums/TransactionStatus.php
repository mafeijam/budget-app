<?php

namespace App\Enums;

/**
 * Where a transaction sits in its lifecycle.
 *
 * This is a column rather than a field in the JSON meta bag for two reasons: it
 * has to be filterable, and it has to be excludable from a balance. A card
 * charge is `pending` until the issuer posts it, and a trade is unsettled until
 * T+2, so neither belongs in an "owed" total until it settles.
 *
 * The default is `posted` because that is the only state a plain cash expense
 * is ever in.
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Settled = 'settled';

    /**
     * Whether a row in this state counts toward a balance.
     *
     * A pending card charge has not actually been billed yet, so including it
     * would overstate what is owed.
     */
    public function countsTowardBalance(): bool
    {
        return $this !== self::Pending;
    }
}
