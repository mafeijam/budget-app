<?php

namespace App\DTO;

use App\Enums\TransactionType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * The fields only some transaction types have, in the JSON meta bag so that a new
 * one needs no migration.
 *
 * A DTO rather than a plain array because the rules are conditional on the root
 * `type`: a charge has a merchant, a trade a symbol and a price, and neither is
 * meaningful for the other.
 *
 * Every rule is `nullable`, because the validator counts null as present and both
 * a blank number field and TransactionData::empty() produce one -- without it the
 * type and range rules would fire on every field of every row that does not use
 * them. `required_if` is implicit and survives, so a charge still has to name its
 * merchant.
 */
class TransactionMetaData extends Data
{
    /**
     * The amount column's scale, decimal(12,4). Referenced by the arithmetic below
     * rather than repeated as a literal, so a derived amount cannot drift from
     * what the column can store.
     */
    public const AMOUNT_SCALE = 4;

    /** decimal(12,4) holds eight integer digits and four decimal places. */
    public const MAX_AMOUNT = '99999999.9999';

    /**
     * The exchange rate's scale and ceiling.
     *
     * These were decimal(16,8) on the column and are stated here instead, because
     * there is no column to state them now. The scale was always in the rules; the
     * ceiling was not, because `decimal:0,8` counts decimal places and says
     * nothing about integer digits -- so a thirty-digit rate passed the DTO and
     * was refused by the database, or rounded down. With the column gone the DTO
     * is the only gate, and it has to be one that closes.
     */
    public const FX_SCALE = 8;

    public const MAX_FX_RATE = '99999999.99999999';

    public function __construct(
        // Card charges: who was paid. A payment has none -- it settles a statement
        // rather than buying anything.
        public ?string $merchant,

        // Securities trades. Fractional shares need more places than money does,
        // hence eight against the amount's four.
        public ?string $symbol,
        public ?string $quantity,
        public ?string $unit_price,
        public ?string $fees,

        // Converts amount, denominated in the transaction's own ccy, into the
        // owning account's currency. NULL means already in the account's currency,
        // which is the common case and is never forced to a literal 1. Nothing
        // converts with it yet -- see AccountData::guardSettlementAccount().
        public ?string $fx_rate,
    ) {}

    public static function rules()
    {
        return [
            'merchant' => [
                'nullable',
                'required_unless:type,'.self::typesExcept(TransactionType::Charge),
                'max:255',
            ],
            'symbol' => [
                'nullable',
                'required_unless:type,'.self::typesExcept(TransactionType::Buy, TransactionType::Sell),
                'max:32',
            ],
            'quantity' => [
                'nullable',
                'required_unless:type,'.self::typesExcept(TransactionType::Buy, TransactionType::Sell),
                'decimal:0,8',
                'gt:0',
            ],
            'unit_price' => [
                'nullable',
                'required_unless:type,'.self::typesExcept(TransactionType::Buy, TransactionType::Sell),
                'decimal:0,4',
                'gt:0',
            ],
            'fees' => ['nullable', 'decimal:0,4', 'min:0'],

            // Optional for every type, because whether a rate is needed depends on
            // the pairing of the transaction's currency with its account's, and
            // neither is knowable from the payload alone. `max` is the half that
            // used to be the column's job; see MAX_FX_RATE.
            'fx_rate' => [
                'nullable',
                'decimal:0,'.self::FX_SCALE,
                'gt:0',
                'max:'.self::MAX_FX_RATE,
            ],
        ];
    }

    /**
     * Every transaction type except the ones named, as a comma-separated list.
     *
     * There is no `required_if_in` in this Laravel: it is silently accepted and
     * then skipped, so a trade could be recorded with no symbol and no quantity
     * and nothing would complain.
     *
     * Deriving the list also means a new case is reflected automatically, and an
     * unrecognised type falls outside it and so has the field required -- the right
     * way round, since the enum rejects the bad type in any case.
     */
    private static function typesExcept(TransactionType ...$permitted): string
    {
        return collect(TransactionType::cases())
            ->reject(fn (TransactionType $type) => in_array($type, $permitted, true))
            ->map(fn (TransactionType $type) => $type->value)
            ->implode(',');
    }

    public static function attributes()
    {
        return [
            'merchant' => 'merchant',
            'symbol' => 'symbol',
            'quantity' => 'quantity',
            'unit_price' => 'unit price',
            'fees' => 'fees',
            'fx_rate' => 'exchange rate',
        ];
    }

    /**
     * The amount implied by a trade's quantity, price and fee.
     *
     * Null for every type that does not derive: a dividend is recorded on a
     * securities account but its amount is simply stated, so deriving one from
     * whatever symbol the account happens to hold would be nonsense.
     *
     * The fee is folded in net rather than left as a transaction of its own, since
     * it is part of this trade and never appears in a balance alone. A buy costs
     * price x quantity + fees and a sell yields price x quantity - fees; in both
     * cases the magnitude that actually moves the account. Adding the fee to a sell
     * would overstate the balance by exactly the brokerage.
     *
     * Amount is a positive magnitude whose direction comes from the account type
     * and the transaction type, so a negative result is a data error -- usually a
     * fee entered against the wrong side. Zero is fine: a sell netting to nothing
     * closed with nothing left to deposit.
     *
     * Decimal, not float: Float64 has no decimal semantics, so it survives these
     * magnitudes by luck rather than by guarantee, and a wider quantity or a
     * change of scale would break it silently.
     */
    public function derivedAmount(TransactionType $type): ?string
    {
        if (! $type->derivesAmount()) {
            return null;
        }

        $gross = BigDecimal::of($this->quantity)->multipliedBy(BigDecimal::of($this->unit_price));
        $fees = $this->fees === null ? BigDecimal::zero() : BigDecimal::of($this->fees);

        $net = $type === TransactionType::Buy
            ? $gross->plus($fees)
            : $gross->minus($fees);

        $scaled = $net->toScale(self::AMOUNT_SCALE, RoundingMode::HalfUp);

        if ($scaled->isNegative()) {
            throw new InvalidArgumentException(
                "A {$type->value} of {$this->quantity} at {$this->unit_price} with fees of "
                ."{$this->fees} nets to a negative amount. Fees exceed the proceeds, which "
                .'usually means the fee was entered against the wrong side of the trade.'
            );
        }

        $max = BigDecimal::of(self::MAX_AMOUNT);

        if ($scaled->isGreaterThan($max)) {
            throw new InvalidArgumentException(
                "A {$type->value} of {$this->quantity} at {$this->unit_price} comes to "
                ."{$scaled}, which is past the {$max} the amount column can hold. Left to the "
                .'database this would be silently rounded down, losing money with no error.'
            );
        }

        return $scaled->toString();
    }
}
