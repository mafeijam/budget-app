<?php

namespace App\Enums;

/**
 * A column rather than meta, because balances filter on it.
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Posted = 'posted';

    public function countsTowardBalance(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * For SQL, so a query never hand-writes an IN list that drifts from the enum.
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
