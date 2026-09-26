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

        // The statement period a charge rolls up into, and so the day it is
        // payable. Derived for a charge; a payment supplies it to say which
        // statement it settles. NULL otherwise.
        public ?string $due_date,

        public ?TransactionMetaData $meta_data,
        public ?Carbon $created_at,
    ) {
        $this->created_at ??= now();
        $this->status ??= TransactionStatus::Posted;

        // One account read serves both the pairing check and the due date.
        $account = Account::find($this->account_id);

        $this->guardAccountType($account);
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
            // difference is the transaction's fx_rate's whole purpose; not widened
            // to all of ISO 4217, which would let a transaction record a code no
            // account can hold and no dropdown anywhere offers.
            //
            // fx_rate itself is declared in TransactionMetaData: it is
            // type-specific, so it belongs with the merchant and the trade fields.
            'due_date' => ['nullable', 'date_format:Y-m-d'],

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
            'due_date' => 'due date',
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
     * Place a charge in the statement period its date falls in.
     *
     * Only a charge. A payment's due date names the statement it settles, normally
     * the earliest unpaid -- a question about outstanding balances that belongs in
     * the controller. A card with no statement day yields no due date rather than
     * one counted from the payment term alone, which would be a whole cycle out.
     */
    private function deriveDueDate(?Account $account): void
    {
        if ($this->due_date !== null || $this->type !== TransactionType::Charge) {
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

        $this->due_date = $cycle->dueDateFor($charge)->toDateString();
    }
}
