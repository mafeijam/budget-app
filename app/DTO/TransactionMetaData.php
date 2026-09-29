<?php

namespace App\DTO;

use App\Enums\TransactionType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * The fields only some transaction types have, kept in the meta bag. Every rule is
 * `nullable` first, since the validator counts null as present.
 */
class TransactionMetaData extends Data
{
    /** The amount column's scale, decimal(12,4). */
    public const AMOUNT_SCALE = 4;

    public const MAX_AMOUNT = '99999999.9999';

    public function __construct(
        // Trades and dividends. A quantity takes eight places, for fractional shares.
        public ?string $symbol = null,
        public ?string $quantity = null,
        public ?string $unit_price = null,
        public ?string $fees = null,

        // The money moved outside these accounts, so TradeCash writes no cash row.
        // Null rather than false when it did, so absence is the only way to say no.
        public ?bool $no_cash = null,

        // The statement a charge rolls up into, or a payment settles.
        public ?string $due_date = null,

        // A foreign-currency charge in the card's own currency. See guardCardAmount().
        public ?string $card_amount = null,

        // Server-owned links, restored by keepLinksOf(). destroy() deletes whatever
        // paired_transaction_id points at; settled_by records which payment closed a
        // charge's statement, and is never the test of whether it is paid.
        public ?int $paired_transaction_id = null,
        public ?int $settled_by = null,
    ) {}

    public static function rules()
    {
        $cashSide = self::typesWhere(fn (TransactionType $type) => $type->needsCashSide());
        $noCashSide = self::typesWhere(fn (TransactionType $type) => ! $type->needsCashSide());
        $notDerived = self::typesWhere(fn (TransactionType $type) => ! $type->derivesAmount());

        return [
            'symbol' => ['nullable', 'required_unless:type,'.$noCashSide, 'max:32'],
            'quantity' => ['nullable', 'required_unless:type,'.$notDerived, 'decimal:0,8', 'gt:0'],
            'unit_price' => ['nullable', 'required_unless:type,'.$notDerived, 'decimal:0,4', 'gt:0'],
            'fees' => ['nullable', 'decimal:0,4', 'min:0'],
            'no_cash' => ['nullable', 'prohibited_unless:type,'.$cashSide],

            // Not required for a charge: it is derived after validation.
            'due_date' => ['nullable', 'date_format:Y-m-d'],

            // Whether it is needed depends on the account row, which a rule cannot see;
            // this only bounds it, so a zero is refused rather than summed.
            'card_amount' => ['nullable', 'decimal:0,'.self::AMOUNT_SCALE, 'gt:0', 'max:'.self::MAX_AMOUNT],
        ];
    }

    /**
     * The transaction types matching a predicate, as a comma-separated list.
     *
     * Derived from the enum, because this Laravel has no `required_if_in`: it is
     * accepted and silently never run.
     */
    private static function typesWhere(callable $predicate): string
    {
        return collect(TransactionType::cases())
            ->filter($predicate)
            ->map(fn (TransactionType $type) => $type->value)
            ->implode(',');
    }

    public static function attributes()
    {
        return [
            'symbol' => 'symbol',
            'quantity' => 'quantity',
            'unit_price' => 'unit price',
            'fees' => 'fees',
            'no_cash' => 'no cash side',
            'due_date' => 'due date',
            'card_amount' => 'amount in the card\'s currency',
        ];
    }

    /** Laravel's own message would list the permitted types. */
    public static function messages()
    {
        return [
            'no_cash.prohibited_unless' => 'Only a buy, a sell or a dividend has a cash side to skip.',
        ];
    }

    /**
     * A trade's amount: price x quantity, plus fees on a buy and minus them on a sell.
     * Null for a type that does not derive.
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

        // Field errors rather than exceptions, or a request would 500.
        if ($scaled->isNegative()) {
            throw ValidationException::withMessages([
                'meta_data.fees' => "A {$type->value} of {$this->quantity} at {$this->unit_price} with fees "
                    ."of {$this->fees} nets to a negative amount. Fees exceed the proceeds, which "
                    .'usually means the fee was entered against the wrong side of the trade.',
            ]);
        }

        $max = BigDecimal::of(self::MAX_AMOUNT);

        // The database would round it down silently.
        if ($scaled->isGreaterThan($max)) {
            throw ValidationException::withMessages([
                'meta_data.quantity' => "A {$type->value} of {$this->quantity} at {$this->unit_price} comes "
                    ."to {$scaled}, which is past the {$max} the amount column can hold. Left to the "
                    .'database this would be silently rounded down, losing money with no error.',
            ]);
        }

        return $scaled->toString();
    }
}
