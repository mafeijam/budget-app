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
 * One row of the shared transactions table.
 *
 * The table holds cash, credit card and stock trading rows, which is what makes
 * this DTO conditional rather than uniform. Three things follow from that, and
 * each is handled in a different place because no single mechanism covers all of
 * them:
 *
 *  - The transaction type must be legal on the owning account's type. That needs
 *    the account row, which only the database knows, so it is checked in the
 *    constructor rather than in rules() -- see guardAccountType().
 *
 *  - The amount is derived for a trade and supplied for everything else. The
 *    constructor derives it and rules() states which is which, so a caller that
 *    skips validate() still cannot set its own trade amount.
 *
 *  - A charge's due date comes from the account's statement terms. Also derived
 *    in the constructor, for the same reason.
 *
 * amount is a positive magnitude in every case. Which way it moves the balance
 * is decided by the account type and the transaction type together, not by a
 * sign, so there is no way to record a negative and no need to.
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

        // Nullable only because a trade derives its own. Rules make it required
        // for every other type and prohibited for the trades.
        public ?string $amount,
        public Currency $ccy,

        // Defaults to posted below; that is the only state a plain cash expense
        // is ever in, so a client that does not care should not have to say.
        public ?TransactionStatus $status,

        // Converts amount, which is denominated in ccy, into the account's own
        // currency. NULL means "already in the account's currency".
        public ?string $fx_rate,

        // The statement period a charge rolls up into, and so the day it is
        // payable. Derived for a charge; a payment supplies it to say which
        // statement it settles. NULL for everything else.
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
     * Only the constraints the property types cannot express are declared here.
     * spatie/laravel-data already derives `required` and the type checks from
     * the constructor signature, the same way AccountData does it.
     */
    public static function rules()
    {
        return [
            'account_id' => ['exists:accounts,id'],

            // Required only for the types that are categorised spending. A
            // payment and a trade may leave it null; a payment may also be
            // labelled, since the rule below is required_unless rather than
            // prohibited_unless. Nothing in the settlement arithmetic reads this
            // column, so a label on a payment is inert to the balance and worth
            // keeping for the rows a payment actually refers to.
            'category_id' => [
                'nullable',
                'exists:categories,id',
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->requiresCategory()),
            ],

            // The column is a `date`, so only the ISO calendar date is
            // meaningful. No time component, no locale formats.
            'date' => ['date_format:Y-m-d'],

            // The column is decimal(12,4). Note that Laravel's `decimal` rule
            // counts *decimal places*, not integer digits, so `decimal:0,4`
            // caps the scale at four; `max` then caps the magnitude using the
            // eight digits the precision leaves for the integer part.
            //
            // Amount is deliberately a string. It is money, and a float would
            // introduce binary rounding errors. Do not "fix" it to a numeric
            // type; widen the rules instead.
            //
            // Amount is required for everything that is not a trade, and
            // prohibited for a trade -- two rules over two *different* lists, one
            // naming the trades and one naming everything else. Sharing a single
            // list between them is a trap: `required_unless:<non-trades>` reads
            // as "required unless it is not a trade", which is a trade, so it
            // demands an amount on exactly the rows that must not have one.
            //
            // There is no `required_if_in` or `prohibited_if_in` in this version
            // of Laravel. Both are accepted into the rule array and then never
            // run, which fails silently.
            'amount' => [
                'nullable',
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => $t->derivesAmount()),
                'prohibited_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->derivesAmount()),
                'decimal:0,'.TransactionMetaData::AMOUNT_SCALE,
                'min:0',
                'max:'.TransactionMetaData::MAX_AMOUNT,
            ],

            'description' => ['max:255'],

            // No rule, for the reason AccountData's has none: the property is
            // typed as Currency, so spatie/laravel-data derives the membership
            // check. The old `size:3` was the same half-measure as the account
            // one -- it accepted 'ZZZ' and 'hkd' as readily as 'HKD'.
            //
            // The same enum as the account's currency, and deliberately not a
            // wider one. A transaction may be denominated in something other than
            // its account's currency -- that is what fx_rate is for -- so this
            // cannot be narrowed to "the account's ccy". It could be widened to
            // all of ISO 4217, and that is the day a transaction records a code
            // no account can hold and no dropdown anywhere offers.
            'fx_rate' => ['nullable', 'decimal:0,8', 'gt:0'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],

            // A trade must bring its meta. Without this a buy with no meta_data
            // at all passes: the nested rules for symbol, quantity and unit
            // price never run when the key is missing, so nothing asks for the
            // numbers the amount is derived from, and the row arrives with a
            // null amount for a NOT NULL column.
            'meta_data' => [
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->derivesAmount()),
            ],
        ];
    }

    public static function attributes()
    {
        return [
            'ccy' => 'currency',
            'fx_rate' => 'exchange rate',
            'due_date' => 'due date',
        ];
    }

    /**
     * The transaction types matching a predicate, as a comma-separated list.
     *
     * Built from the enum so the conditional rules cannot go stale when a type is
     * added. A hand-written list of the types that do *not* need a category, or
     * do *not* derive an amount, is exactly the kind that quietly stops matching
     * the enum -- and a stale list here fails open, admitting rows that should
     * have been rejected.
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
     * This is a constructor check rather than a rule because a rule can only see
     * the payload, and what is legal depends on the account row. Doing it here
     * means it holds whether or not the caller remembered to call validate().
     *
     * Reported as a ValidationException so it reaches the form as a field error
     * rather than a 500.
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
     * Fill in a trade's amount, or clear any amount a client tried to supply.
     *
     * Assignment rather than `??=` on purpose: for a trade the derived figure is
     * the only correct one, so a supplied amount is overwritten even if the
     * caller never validates. The prohibited rule still tells the client off.
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
     * Only a charge. A payment's due date names the statement it settles, which
     * is normally the earliest unpaid -- a question about outstanding balances
     * that belongs in the controller, not here.
     *
     * A card whose meta has no statement day yields no due date rather than one
     * guessed from the due day alone, which would be a whole cycle out.
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
            // The date rule reports the bad format. Throwing a parse error on
            // the way past would show the user an exception instead.
            return;
        }

        $this->due_date = $cycle->dueDateFor($charge)->toDateString();
    }
}
