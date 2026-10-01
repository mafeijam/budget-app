<?php

namespace App\Support;

use App\Enums\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Converting into the base currency, at Yahoo's FX pair for the day, kept in the prices
 * table beside the stock closes: USDHKD=X is what one US dollar is in Hong Kong dollars.
 *
 * A rate is read on or before the day, so a weekend or a holiday takes the last close.
 * A day with no rate at all returns null rather than 1: money in another currency counted
 * one-for-one would put a yen balance into the total as that many dollars.
 */
class Fx
{
    public const BASE = Currency::Hkd;

    public static function pair(string $ccy): string
    {
        return $ccy.self::BASE->value.'=X';
    }

    /** @var array<string, array<string, string>> pair => [date => close], ascending */
    private array $closes = [];

    /** @param  list<string>  $currencies  every currency to be converted */
    public static function for(array $currencies): self
    {
        $fx = new self;

        $pairs = array_map(self::pair(...), array_diff(array_unique($currencies), [self::BASE->value]));

        // Rows, not models: a pair is a close a day for years, read on most pages, and two
        // columns of each are all a rate needs.
        foreach (DB::table('prices')->whereIn('symbol', $pairs)->orderBy('date')->get(['symbol', 'date', 'close']) as $price) {
            $fx->closes[$price->symbol][$price->date] = (string) $price->close;
        }

        return $fx;
    }

    /** One unit of $ccy in the base currency on $day, or null when there is no rate yet. */
    public function rate(string $ccy, string $day): ?string
    {
        if ($ccy === self::BASE->value) {
            return '1';
        }

        $found = null;

        foreach ($this->closes[self::pair($ccy)] ?? [] as $date => $close) {
            if ($date > $day) {
                break;
            }

            $found = $close;
        }

        return $found;
    }

    /** $amount of $ccy in the base currency, or null when there is no rate yet. */
    public function toBase(string $amount, string $ccy, string $day): ?BigDecimal
    {
        $rate = $this->rate($ccy, $day);

        return $rate === null
            ? null
            : BigDecimal::of($amount)->multipliedBy($rate)->toScale(4, RoundingMode::HalfUp);
    }
}
