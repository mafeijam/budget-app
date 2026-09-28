<?php

namespace App\DTO;

use App\Enums\TransactionType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * The fields only some transaction types have, in the meta bag so a new one needs
 * no migration. A DTO rather than an array because the rules are conditional on the
 * root `type` -- a trade has a symbol, a charge a due date. Every rule is
 * `nullable` (the validator counts null as present) while `required_if` survives.
 *
 * A charge's merchant is not among them: `description` says the same thing and is
 * required of every row, so the field was wired to nothing.
 */
class TransactionMetaData extends Data
{
    /**
     * The amount column's scale, decimal(12,4). Referenced rather than repeated, so a
     * derived amount cannot drift from what the column can store.
     */
    public const AMOUNT_SCALE = 4;

    /** decimal(12,4) holds eight integer digits and four decimal places. */
    public const MAX_AMOUNT = '99999999.9999';

    public function __construct(
        // Securities trades. Fractional shares need more places than money does,
        // hence eight against the amount's four.
        public ?string $symbol = null,
        public ?string $quantity = null,
        public ?string $unit_price = null,
        public ?string $fees = null,

        // On a trade: that its money side is not in these accounts, so TradeCash writes
        // no cash row for it. For a position back-dated from before the bank was
        // tracked, or a trade settling through a bank this app does not hold.
        //
        // Null rather than false when the cash side *is* recorded, so the default is
        // what a trade has always done and a bag written before this field existed
        // needs no backfill.
        public ?bool $no_cash = null,

        // The statement period a charge rolls up into, and so the day it is payable.
        // Derived for a charge, supplied by a payment naming the statement it
        // settles, NULL otherwise. A column while MySQL could index it, a bag now
        // that it cannot -- see create_transactions_table.
        public ?string $due_date = null,

        // A charge in a currency other than its card's, as that amount in the card's
        // own currency. See guardCardAmount() in TransactionData for when it is
        // required and why there is no rate anywhere in this.
        public ?string $card_amount = null,

        // The other half of a card settlement, which is two rows and not one. Written
        // by TransactionController::settle() and nothing else, since destroy() deletes
        // whatever this points at: the link is the difference between deleting one row
        // and deleting two. A payload's value never lands -- see keepLinksOf() in
        // TransactionData.
        public ?int $paired_transaction_id = null,

        // On a charge: the payment that settled the statement it is in. Written by
        // settle() and removed by destroy() with that payment, so it is a record of
        // which payment closed the bill -- never the test of whether it is paid, which
        // CardStatement derives, because a claim on one row cannot see the others.
        // Server-owned like the pairing, and kept through an edit the same way.
        public ?int $settled_by = null,
    ) {}

    public static function rules()
    {
        return [
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

            // Prohibited rather than ignored on every other type, which is what
            // card_amount does for the same reason: a flag left sitting in the bag of a
            // row that has no cash side is a claim nothing downstream would ever
            // contradict, so a type change that should have cleared it is caught here.
            //
            // The trades and not the two named, since `prohibited_unless` permits what
            // it lists -- and the trades are already decided by derivesAmount(), which is
            // the same answer TradeCash gives when it asks whether a row has a cash side.
            'no_cash' => [
                'nullable',
                'prohibited_unless:type,'.self::typesWhere(fn (TransactionType $type) => $type->derivesAmount()),
            ],

            // `nullable` first, as on every other key here. Not required for a charge
            // even though the constructor fills it in, because it is filled in after
            // validation and a card with no statement day has none.
            'due_date' => ['nullable', 'date_format:Y-m-d'],

            // Not `required_if`: whether this is needed depends on the charge's currency
            // against the *account row's*, which a rule cannot see. What this does is
            // bound the figure, so a zero or an over-scaled one is refused here rather
            // than summed into a statement and rounded by the database. Hence `gt:0` --
            // a zero is a missing figure wearing a value.
            'card_amount' => [
                'nullable',
                'decimal:0,'.self::AMOUNT_SCALE,
                'gt:0',
                'max:'.self::MAX_AMOUNT,
            ],
        ];
    }

    /**
     * Every transaction type except the ones named, as a comma-separated list.
     *
     * There is no `required_if_in` in this Laravel: it is silently accepted and then
     * skipped, so a trade could be recorded with no symbol and nothing would complain.
     * Deriving the list also puts an unrecognised type outside it, and so inside the
     * requirement -- the right way round, since the enum rejects the bad type anyway.
     */
    private static function typesExcept(TransactionType ...$permitted): string
    {
        return self::typesWhere(fn (TransactionType $type) => ! in_array($type, $permitted, true));
    }

    /**
     * The transaction types matching a predicate, as a comma-separated list.
     *
     * For the rules that name what a field is *for*, where the complement is the wrong
     * way round: `prohibited_unless` permits the types it lists, so listing everything
     * but the trades would permit no_cash on a dividend and refuse it on a buy.
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

    /**
     * What a refusal says, where the rule's own message does not.
     *
     * Laravel's message for prohibited_unless restates the condition -- "prohibited
     * unless type is in buy, sell" -- which tells the user the rule rather than why
     * their row was turned down. There is no `prohibited_if_in` to say it in one word,
     * so the sentence is written here.
     */
    public static function messages()
    {
        return [
            'no_cash.prohibited_unless' => 'Only a buy or a sell has a cash side to skip.',
        ];
    }

    /**
     * The amount implied by a trade's quantity, price and fee.
     *
     * Null for every type that does not derive. The fee is folded in net rather than
     * left as a transaction of its own, since it is part of this trade and never
     * appears in a balance alone: a buy costs price x quantity + fees, a sell yields
     * price x quantity - fees, and adding the fee to a sell would overstate the
     * balance by exactly the brokerage.
     *
     * Decimal, not float: Float64 has no decimal semantics, so it survives these
     * magnitudes by luck and a wider quantity would break it silently.
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

        // Field errors rather than exceptions, keyed where TransactionData nests this
        // bag: the constructor calls this on a request, and anything else there is a 500
        // with the user's figures gone and nothing on the form to say which was wrong.
        if ($scaled->isNegative()) {
            throw ValidationException::withMessages([
                'meta_data.fees' => "A {$type->value} of {$this->quantity} at {$this->unit_price} with fees "
                    ."of {$this->fees} nets to a negative amount. Fees exceed the proceeds, which "
                    .'usually means the fee was entered against the wrong side of the trade.',
            ]);
        }

        $max = BigDecimal::of(self::MAX_AMOUNT);

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
