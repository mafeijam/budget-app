<?php

namespace App\Enums;

/**
 * Where a transaction sits in its lifecycle.
 *
 * This is a column rather than a field in the JSON meta bag for two reasons: it
 * has to be filterable, and it has to be excludable from a balance. A card
 * charge is `pending` until the issuer posts it, and a trade is pending until it
 * settles, so neither belongs in an "owed" total until it settles.
 *
 * The default is `posted` because that is the only state a plain cash expense
 * is ever in.
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';

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

    /**
     * The states that count toward a balance, as column values.
     *
     * Here rather than written into a query, because a hand-written IN list is a
     * second statement of the rule above and the two would drift: a case added to
     * this enum would be left out of the list, and the balance would quietly
     * exclude a state nobody told it to. Used by CardStatement to decide which
     * charges and payments are owed.
     *
     * @return array<int, string>
     */
    public static function countingTowardBalance(): array
    {
        return array_column(
            array_filter(self::cases(), fn (self $status) => $status->countsTowardBalance()),
            'value'
        );
    }
}
