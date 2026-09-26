<?php

namespace App\Enums;

/**
 * The currencies an account or transaction may be denominated in.
 *
 * This is the single source of truth for the allowed set. Typing the property as
 * Currency makes spatie/laravel-data reject anything else automatically, which
 * matters because the column was previously only length-capped: 'ZZZ' and
 * 'hkd' were both accepted, so a typo became a row that no balance query could
 * interpret and no form could offer again.
 *
 * A shortlist rather than all ~180 active ISO 4217 codes. The full list needs a
 * searchable picker and a maintained name for every entry, and an account the
 * user cannot find in a dropdown is worse than an account they cannot create.
 * Adding one is a case and a name below.
 *
 * TWD is not an official ISO 4217 assignment -- Taiwan is not assigned a code
 * and the currency circulates under this de facto one -- but it is what an
 * account in this region will actually hold, so the set follows use rather than
 * the standard's letter.
 */
enum Currency: string
{
    case Hkd = 'HKD';
    case Usd = 'USD';
    case Cny = 'CNY';
    case Jpy = 'JPY';
    case Aud = 'AUD';
    case Twd = 'TWD';

    /**
     * The text shown in the currency dropdown: code first, then the name.
     *
     * Code first because the code is what gets stored and what the account table
     * and the settlement picker already display; the name is there so a user
     * picking between CNY and JPY does not have to know which is which.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hkd => 'HKD — Hong Kong Dollar',
            self::Usd => 'USD — US Dollar',
            self::Cny => 'CNY — Chinese Yuan',
            self::Jpy => 'JPY — Japanese Yen',
            self::Aud => 'AUD — Australian Dollar',
            self::Twd => 'TWD — New Taiwan Dollar',
        };
    }
}
