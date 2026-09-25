<?php

namespace App\DTO;

use App\Enums\TransactionType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * The fields that only some transaction types have.
 *
 * Stored in the JSON meta bag rather than as columns, so adding a field needs
 * no migration. What makes this a DTO rather than a plain array is that the
 * rules are conditional on the transaction type: a charge has a merchant, a
 * trade has a symbol and a price, and neither is meaningful for the other.
 * The condition is read from the root `type`, so it resolves against the
 * transaction rather than against anything in the meta bag itself.
 *
 * The rules are also all `nullable`. A blank number field and
 * TransactionData::empty() both produce null, and the validator counts null as
 * "present", so without `nullable` the type and range rules would fire on
 * every field of every row that does not use it. `required_if` is implicit and
 * survives, so a charge still has to name its merchant.
 */
class TransactionMetaData extends Data
{
    /**
     * The scale of the amount column, decimal(12,4).
     *
     * Referenced by the arithmetic below rather than repeated as a literal, so
     * a derived amount cannot drift away from what the column can store.
     */
    public const AMOUNT_SCALE = 4;

    /**
     * The largest amount decimal(12,4) can hold: eight integer digits, four
     * decimal places.
     */
    public const MAX_AMOUNT = '99999999.9999';

    public function __construct(
        // Card charges: who was paid. A payment has none -- it settles a
        // statement rather than buying anything.
        public ?string $merchant,

        // Securities trades. Fractional shares mean quantity needs more places
        // than money does, so it carries eight where the amount carries four.
        public ?string $symbol,
        public ?string $quantity,
        public ?string $unit_price,
        public ?string $fees,
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
        ];
    }

    /**
     * Every transaction type except the ones named, as a comma-separated list.
     *
     * Used to build a "required unless" rule from the enum rather than writing
     * the types out by hand. Two reasons that matters:
     *
     * There is no `required_if_in` in this version of Laravel. It was silently
     * accepted and then skipped, so a trade could be recorded with no symbol
     * and no quantity and nothing would complain. Stacking two `required_if`
     * rules would work too, but the exclusion list still has to be spelled out
     * -- and a hand-written list of the types that do *not* need a field is the
     * kind that quietly goes stale the moment a type is added.
     *
     * Deriving it means a new TransactionType case is reflected here
     * automatically, and an unrecognised type falls outside the list, which
     * makes the field required. That is the right way round: the enum rejects
     * the bad type, and until it does, the stricter field rule applies.
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
        ];
    }

    /**
     * The amount implied by a trade's quantity, price and fee.
     *
     * Null for every type that is not a trade: a dividend is recorded on a
     * securities account but its amount is simply stated, so deriving one from
     * whatever symbol happens to be on the account would be nonsense. The
     * caller decides which types derive, via TransactionType::derivesAmount().
     *
     * The fee is folded in net rather than left as a separate transaction,
     * because it is part of the same trade and does not appear in a balance on
     * its own. A buy therefore costs price x quantity + fees, and a sell
     * yields price x quantity - fees: in both cases the magnitude that actually
     * moves the account. Adding the fee to a sell would overstate the balance
     * by exactly the brokerage.
     *
     * Amount is a positive magnitude whose direction comes from the account
     * type and the transaction type, so a negative result has nowhere to go and
     * is a data error -- usually a fee entered against the wrong side. Zero is
     * fine: a sell that nets to nothing closed with nothing left to deposit.
     *
     * The arithmetic is decimal, not float. Float64 has no decimal semantics,
     * so it happens to survive these magnitudes by luck rather than by
     * guarantee, and a wider quantity or a change of scale would break it
     * silently. Decimals make the result exact for anything the rules accept,
     * and make the rounding and the magnitude check explicit rather than
     * emergent from PHP's `precision` setting.
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
