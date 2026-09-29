<?php

namespace App\Enums;

/**
 * A shortlist, not all of ISO 4217. TWD is de facto rather than assigned, and
 * kept because it is what accounts here hold.
 */
enum Currency: string
{
    case Hkd = 'HKD';
    case Usd = 'USD';
    case Cny = 'CNY';
    case Jpy = 'JPY';
    case Aud = 'AUD';
    case Twd = 'TWD';
    case Krw = 'KRW';

    public function label(): string
    {
        return match ($this) {
            self::Hkd => 'HKD — Hong Kong Dollar',
            self::Usd => 'USD — US Dollar',
            self::Cny => 'CNY — Chinese Yuan',
            self::Jpy => 'JPY — Japanese Yen',
            self::Aud => 'AUD — Australian Dollar',
            self::Twd => 'TWD — New Taiwan Dollar',
            self::Krw => 'KRW — South Korean Won',
        };
    }
}
