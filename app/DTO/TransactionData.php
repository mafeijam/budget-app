<?php

namespace App\DTO;

use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * One row of the shared transactions table, which holds cash, card and trade rows
 * alike -- hence conditional rather than uniform. Three consequences, each handled
 * where it can be: the type must be legal on the owning account's type (the
 * constructor, since only the database knows the account), a trade's amount and a
 * charge's due date are derived (the constructor), and the payload constraints are
 * declared in rules().
 *
 * amount is a positive magnitude throughout. Which way it moves the balance comes
 * from the account type and the transaction type together, so a negative cannot be
 * recorded and need not be.
 */
class TransactionData extends Data
{
    public function __construct(
        public ?int $id,
        public int $account_id,
        public ?int $category_id,
        public string $date,
        public TransactionType $type,
        public string $description,

        // Null only because a trade derives its own; rules() require it for
        // everything else and prohibit it for trades.
        public ?string $amount,
        public Currency $ccy,

        // Defaults to posted, the only state a plain cash expense is ever in.
        public ?TransactionStatus $status,

        public ?TransactionMetaData $meta_data,
        public ?Carbon $created_at,
    ) {
        $this->created_at ??= now();
        $this->status ??= TransactionStatus::Posted;

        // One account read serves both the pairing check and the due date.
        $account = Account::find($this->account_id);

        $this->guardAccountType($account);
        $this->guardCardAmount($account);
        $this->deriveAmount();
        $this->deriveDueDate($account);
    }

    /**
     * Only the constraints the property types cannot express. spatie/laravel-data
     * derives `required` and the type checks from the constructor signature.
     */
    public static function rules()
    {
        return [
            'account_id' => ['exists:accounts,id'],

            // Required only for the types that are categorised spending. A payment
            // may still be labelled, which is why this is required_unless rather
            // than prohibited_unless; the settlement arithmetic never reads the
            // column, so a label on a payment is inert to the balance.
            'category_id' => [
                'nullable',
                'exists:categories,id',
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->requiresCategory()),
            ],

            // The column is a `date`, so the ISO calendar date is all that is
            // meaningful. No time component, no locale formats.
            'date' => ['date_format:Y-m-d'],

            // decimal counts *decimal places*, not integer digits, so this caps the
            // scale at four; `max` then caps the magnitude with the eight digits the
            // precision leaves. A string because this is money and a float would
            // bring binary rounding in -- widen the rules rather than retyping it.
            //
            // Two rules over two *different* lists, and sharing one is a trap:
            // `required_unless:<non-trades>` reads as "required unless it is not a
            // trade", which is a trade, so it demands an amount on exactly the rows
            // that must not have one. There is no `required_if_in` or
            // `prohibited_if_in` in this Laravel either -- both are accepted into
            // the array and then never run, failing silently.
            'amount' => [
                'nullable',
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => $t->derivesAmount()),
                'prohibited_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->derivesAmount()),
                'decimal:0,'.TransactionMetaData::AMOUNT_SCALE,
                'min:0',
                'max:'.TransactionMetaData::MAX_AMOUNT,
            ],

            'description' => ['max:255'],

            // No membership rule is needed -- Currency is the type, so spatie
            // derives it. Not narrowed to the account's ccy, since accommodating a
            // difference is what card_amount is for, on a charge; not widened to all
            // of ISO 4217, which would let a transaction record a code no account can
            // hold and no dropdown anywhere offers.
            //
            // card_amount and due_date are declared in TransactionMetaData rather
            // than here: both are type-specific, so they belong with the merchant
            // and the trade fields. A payment supplies a due date to name the
            // statement it settles, which is why the top-level key it used to sit on
            // was not a payment's field either.
            //
            // Without this a trade with no meta_data at all passes: the nested
            // rules never run on a missing key, so nothing asks for the numbers the
            // amount is derived from and the row reaches a NOT NULL column null.
            'meta_data' => [
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->derivesAmount()),
            ],
        ];
    }

    public static function attributes()
    {
        return [
            'ccy' => 'currency',
        ];
    }

    /**
     * The transaction types matching a predicate, as a comma-separated list.
     *
     * Derived from the enum so the rules cannot go stale when a type is added: a
     * hand-written exclusion list quietly stops matching, and here that fails open.
     */
    private static function typesWhere(callable $predicate): string
    {
        return collect(TransactionType::cases())
            ->filter($predicate)
            ->map(fn (TransactionType $type) => $type->value)
            ->implode(',');
    }

    /**
     * Reject a transaction type that does not belong on the account's type.
     *
     * A constructor check rather than a rule, because a rule sees only the payload
     * and what is legal depends on the account row -- so it holds whether or not
     * the caller remembered to call validate(). A ValidationException so it reaches
     * the form as a field error rather than a 500.
     */
    private function guardAccountType(?Account $account): void
    {
        // No account to compare against: `exists:accounts,id` reports that, and
        // throwing here as well would mask it.
        if ($account === null) {
            return;
        }

        if (! $this->type->isAllowedFor(AccountType::from($account->type))) {
            throw ValidationException::withMessages([
                'type' => "A {$this->type->value} cannot be recorded on a {$account->type} account.",
            ]);
        }
    }

    /**
     * Fill in a trade's amount, or clear any a client tried to supply.
     *
     * Assignment rather than `??=`: for a trade the derived figure is the only
     * correct one, so a supplied amount is overwritten even unvalidated.
     */
    private function deriveAmount(): void
    {
        if (! $this->type->derivesAmount()) {
            return;
        }

        $this->amount = $this->meta_data?->derivedAmount($this->type);
    }

    /**
     * Demand the card-currency figure for a charge entered in another currency.
     *
     * A card's charges may be in whatever currency the merchant charged in, while the
     * statement is in the card's own. So a charge in USD on an HKD card stores 100 and
     * contributes 780 to what the card owes, and the difference between those two
     * numbers is not derivable from anything this app holds.
     *
     * Hence the user types it. Required rather than optional with a fallback to
     * `amount`, because the fallback is silent: a forgotten figure would contribute
     * the raw amount in the wrong currency, and a statement that is quietly wrong is
     * worse than one that refuses to be recorded. A constructor check for the same
     * reason guardAccountType() is one -- the account row is the other half of the
     * comparison and a rule cannot see it.
     *
     * Only a charge. A payment is in the card's currency by definition, and the bank
     * it is paid from must be in the same one, so nothing on that side needs
     * converting either. See AccountData::guardSettlementAccount().
     */
    private function guardCardAmount(?Account $account): void
    {
        if ($account === null || $this->type !== TransactionType::Charge) {
            return;
        }

        if ($this->ccy->value === $account->ccy) {
            // Refused rather than ignored. A figure on a charge already in the card's
            // currency is not merely redundant: CardStatement prefers card_amount over
            // amount, so a stale one left over from when the charge was entered in
            // another currency would silently replace the real amount in what the card
            // owes. Ignored would mean accepted, and the statement would be wrong with
            // nothing reporting it.
            if ($this->meta_data?->card_amount !== null) {
                throw ValidationException::withMessages([
                    'meta_data.card_amount' => sprintf(
                        'This charge is already in the card\'s currency (%s), so it needs no separate amount.',
                        $this->ccy->value
                    ),
                ]);
            }

            return;
        }

        if ($this->meta_data?->card_amount !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'meta_data.card_amount' => sprintf(
                'This charge is in %s and the card is in %s, so the amount in the card\'s currency is required.',
                $this->ccy->value,
                $account->ccy
            ),
        ]);
    }

    /**
     * Place a charge in the statement period its date falls in.
     *
     * Only a charge. A payment's due date names the statement it settles, normally
     * the earliest unpaid -- a question about outstanding balances that belongs in
     * the controller. A card with no statement day yields no due date rather than
     * one counted from the payment term alone, which would be a whole cycle out.
     *
     * The date lives in the meta bag, which is created if the payload did not
     * carry one. That is not tidiness: meta_data is required for a trade and
     * optional for everything else, so a charge that sends no bag is a valid
     * payload, and skipping the derivation for it would drop the charge out of
     * its statement's settlement figure with nothing anywhere reporting a
     * problem. The one asymmetry this introduces is a non-charge arriving with no
     * bag still gets none, since nothing would be written into it.
     */
    private function deriveDueDate(?Account $account): void
    {
        if ($this->type !== TransactionType::Charge) {
            return;
        }

        if ($this->meta_data?->due_date !== null) {
            return;
        }

        $cycle = $account === null ? null : CardStatementCycle::fromMeta($account->meta?->meta);

        if ($cycle === null) {
            return;
        }

        try {
            $charge = Carbon::createFromFormat('Y-m-d', $this->date);
        } catch (Throwable) {
            // The date rule reports the bad format. Throwing a parse error on the
            // way past would show the user an exception instead.
            return;
        }

        $this->meta_data ??= new TransactionMetaData;
        $this->meta_data->due_date = $cycle->dueDateFor($charge)->toDateString();
    }
}
