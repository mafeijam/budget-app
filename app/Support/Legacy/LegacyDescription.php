<?php

namespace App\Support\Legacy;

use App\Enums\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Reads the old database's free-text descriptions, which are the only place several
 * facts survive: a foreign charge's amount, a trade's quantity and price.
 *
 * Every figure here is a guess about a string, so each parse is checked against
 * something independent -- a foreign charge's implied exchange rate, a trade's fees
 * against the cash it moved -- and anything that fails comes back null for the report
 * to list. A silently misread figure is the failure worth designing against: reading
 * "1.004 YEN" literally leaves the card's HKD balance exactly right and its currency
 * nonsense, and nothing downstream would ever notice.
 */
class LegacyDescription
{
    /**
     * HKD per one unit: generous enough for 2016-2026 and tight enough to reject a
     * misread. A yen is under a tenth of a dollar; a US dollar has never been under
     * seven. Observed in the old data: JPY 0.047-0.074, USD 7.81, AUD 5.73, TWD 0.25,
     * CNY 1.19, KRW 0.0072-0.0074.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PLAUSIBLE_RATE = [
        'JPY' => ['0.040', '0.090'],
        'USD' => ['7.000', '8.500'],
        'AUD' => ['4.500', '6.500'],
        'TWD' => ['0.150', '0.350'],
        'CNY' => ['0.800', '1.500'],
        'KRW' => ['0.005', '0.012'],
    ];

    /**
     * A token counts as a currency only on a word boundary, which is what keeps AUD out of
     * TUSSAUDS and AUDIO, WON out of JAMWONG, and both out of WONDERLAND -- 33 of the 47
     * old descriptions that contain one of these letters are a shop, not a currency. WON
     * is listed but never matches on its own: a number has to sit in front of it, and
     * none of the shops has one.
     */
    private const CURRENCY_TOKEN = '/(?<![A-Z])(YEN|JPY|USD|RMB|CNY|AUD|TWD|KRW|WON|원)(?![A-Z])/iu';

    /** The run of digits before a currency token: "5,000 YEN", " 12 USD", "1.004 YEN". */
    private const FIGURE_BEFORE = '/([0-9][0-9,.]*)\s*$/';

    /**
     * "BUY 3,000 SHARES 6823 HKT-SS @ 10.32", with the quantity sometimes mis-typed as
     * "4,000O SHARES" and the code zero-padded to four.
     */
    private const TRADE = '/^(BUY|SELL)\s+([0-9,]+)O?\s+SHARES\s+([0-9]+)\b.*?@\s*([0-9.]+)\s*$/i';

    /**
     * The foreign amount and currency of a card charge, or null when the description
     * names no currency or the figure cannot be believed.
     *
     * @return array{ccy: string, amount: string}|null
     */
    public function foreignCharge(string $description, string $hkd): ?array
    {
        $description = trim($description);

        if (preg_match(self::CURRENCY_TOKEN, $description, $token, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $ccy = $this->normaliseCurrency($token[1][0]);

        $before = substr($description, 0, $token[1][1]);

        if (preg_match(self::FIGURE_BEFORE, $before, $figure) !== 1) {
            return null;
        }

        // The rate is compared on magnitudes: a refund carries both figures negative.
        $hkdValue = BigDecimal::of($hkd)->abs();

        if ($hkdValue->isZero()) {
            return null;
        }

        $amount = $this->believableFigure($figure[1], $hkdValue, $ccy);

        if ($amount === null) {
            return null;
        }

        return ['ccy' => $ccy, 'amount' => $amount->toScale(4, RoundingMode::HalfUp)->toString()];
    }

    /**
     * The figure before a currency token, choosing between reading a period as a decimal
     * point and as the thousands separator a bank feed often turns it into.
     *
     * "OSAKA 1.004 YEN" against 53.00 HKD reads as a rate of 52.79 taken literally and
     * 0.0528 with the period removed, and only the second is a rate a yen has traded
     * at, so that one is taken. Where both readings are plausible the literal one wins,
     * since it is what the string says.
     */
    private function believableFigure(string $raw, BigDecimal $hkd, string $ccy): ?BigDecimal
    {
        $candidates = [BigDecimal::of(str_replace(',', '', $raw))];

        if (preg_match('/^[0-9]{1,3}\.[0-9]{3}$/', $raw) === 1) {
            $candidates[] = BigDecimal::of(str_replace(['.', ','], '', $raw));
        }

        foreach ($candidates as $candidate) {
            if ($candidate->isPositive() && $this->rateIsPlausible($hkd, $candidate, $ccy)) {
                return $candidate;
            }
        }

        return null;
    }

    private function rateIsPlausible(BigDecimal $hkd, BigDecimal $foreign, string $ccy): bool
    {
        if (! isset(self::PLAUSIBLE_RATE[$ccy])) {
            return false;
        }

        [$low, $high] = self::PLAUSIBLE_RATE[$ccy];

        $rate = $hkd->dividedBy($foreign, 6, RoundingMode::HalfUp);

        return $rate->isGreaterThanOrEqualTo(BigDecimal::of($low))
            && $rate->isLessThanOrEqualTo(BigDecimal::of($high));
    }

    private function normaliseCurrency(string $token): string
    {
        return match (strtoupper($token)) {
            'YEN', 'JPY' => Currency::Jpy->value,
            'RMB', 'CNY' => Currency::Cny->value,
            'WON' => Currency::Krw->value,
            default => strtoupper($token),
        };
    }

    /**
     * A trade the old cash ledger recorded in words, or null when the description is not
     * one. Quantity and unit price come from the string; the fees are whatever the cash
     * moved beyond the product of the two.
     *
     * The unit price is kept as typed even where that leaves the derived amount a few
     * cents off the cash, because a price the account holder entered is evidence and a
     * back-solved one is a guess. The trade is no_cash, so the gap reaches no balance;
     * it is reported instead.
     *
     * @return array{type: string, symbol: string, quantity: string, unit_price: string, fees: string, discrepancy: string}|null
     */
    public function trade(string $description, string $cashAmount): ?array
    {
        if (preg_match(self::TRADE, trim($description), $m) !== 1) {
            return null;
        }

        $quantity = BigDecimal::of(str_replace(',', '', $m[2]));
        $unitPrice = BigDecimal::of($m[4]);

        if (! $quantity->isPositive() || ! $unitPrice->isPositive()) {
            return null;
        }

        $cash = BigDecimal::of($cashAmount)->abs();
        $gross = $quantity->multipliedBy($unitPrice);

        // A buy costs the gross plus commission, a sale returns the gross less it, so
        // the direction is the only difference between them.
        $raw = strtoupper($m[1]) === 'BUY' ? $cash->minus($gross) : $gross->minus($cash);

        // A commission that large means the quantity or the price was misread rather
        // than that the broker charged it, so the row is refused rather than guessed at.
        if ($raw->isGreaterThan($gross->multipliedBy('0.2'))) {
            return null;
        }

        $fees = $raw->isNegative() ? BigDecimal::zero() : $raw;

        $net = strtoupper($m[1]) === 'BUY' ? $gross->plus($fees) : $gross->minus($fees);
        $gap = $cash->minus($net)->abs();

        return [
            'type' => strtolower($m[1]),
            'symbol' => $this->symbol($m[3]),
            'quantity' => $quantity->toScale(8, RoundingMode::HalfUp)->toString(),
            'unit_price' => $unitPrice->toScale(4, RoundingMode::HalfUp)->toString(),
            'fees' => $fees->toScale(4, RoundingMode::HalfUp)->toString(),
            'discrepancy' => $gap->toScale(4, RoundingMode::HalfUp)->toString(),
        ];
    }

    /** A Hong Kong exchange code as the price feed spells it: 5 is 0005.HK, 900 is 0900.HK. */
    public function symbol(string $code): string
    {
        return sprintf('%04d.HK', (int) $code);
    }

    /**
     * The holding a dividend was paid on, from "DIVIDEND 0005 HSBC" or the unspaced
     * "DIVIDEND1098 ROAD KING INFRA". Null for a description naming no code, and for an
     * OFFSET row, which reverses a dividend rather than paying one.
     */
    public function dividendSymbol(string $description): ?string
    {
        $description = trim($description);

        if (preg_match('/^OFFSET\s+DIVIDEND\b/i', $description) === 1) {
            return null;
        }

        if (preg_match('/^DIVIDEND\s*([0-9]{1,5})\b/i', $description, $m) !== 1) {
            return null;
        }

        return $this->symbol($m[1]);
    }

    /** The card a "CARD PAYMENT: VISA" row paid, by name. Null for anything else. */
    public function paidCard(string $description): ?string
    {
        if (preg_match('/^CARD PAYMENT:\s*(.+)$/i', trim($description), $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * The foreign leg of an "HKD TO USD @ 4,988.85" row, or null. The OFFSET row that
     * pairs with it records the HKD equivalent rather than the foreign amount, so the
     * figure survives only here, in the sibling row's description.
     *
     * @return array{ccy: string, amount: string}|null
     */
    public function fxLeg(string $description): ?array
    {
        if (preg_match('/^(?:HKD|USD)\s+TO\s+(HKD|USD)(?:\s*@\s*([0-9,.]+))?$/i', trim($description), $m) !== 1) {
            return null;
        }

        if (! isset($m[2]) || $m[2] === '') {
            return null;
        }

        return [
            'ccy' => strtoupper($m[1]),
            'amount' => BigDecimal::of(str_replace(',', '', $m[2]))->toScale(4, RoundingMode::HalfUp)->toString(),
        ];
    }

    /** Whether a cash row names a foreign currency, which it can only do in its text. */
    public function mentionsForeign(string $description): bool
    {
        return preg_match('/\b(YEN|JPY|USD|RMB|CNY|AUD|TWD|KRW|WON)\b/u', $description) === 1;
    }
}
