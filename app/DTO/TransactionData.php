<?php

namespace App\DTO;

use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\CardStatement;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * One row of the transactions table, which holds cash, card and trade rows alike --
 * hence conditional rather than uniform. amount is a positive magnitude; its direction
 * comes from the account and transaction types together.
 *
 * Payload constraints live in rules(). Anything needing the account row is a
 * constructor check instead, and a ValidationException so it reaches the form as a
 * field error rather than a 500: a rule cannot see the account, and a check here holds
 * whether or not the caller called validate().
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

        // The owning account's name: the model's accessor on a read, the account row
        // on a write. Not a client's to decide -- rules() has no rule for it, and says
        // why. Last and defaulted because an optional parameter ahead of the required
        // ones gets no default at all.
        public ?string $account_name = null,
    ) {
        $this->created_at ??= now();
        $this->status ??= TransactionStatus::Posted;

        // A stored row is shown as stored. The guards judge a payload against the
        // account as it is now, and an account edited since -- a card moved into the
        // currency of its foreign charges -- makes rows that were valid when written
        // throw here, which takes the whole transactions page down with a redirect.
        if (self::$readingStoredRow) {
            return;
        }

        // One account read serves the pairing check, the due date and the name.
        $account = Account::find($this->account_id);

        $this->account_name = $account?->name;

        $this->guardAccountType($account);
        $this->guardCardAmount($account);
        $this->deriveAmount();
        $this->deriveDueDate($account);
    }

    /** Set while fromModel() runs; the constructor has no other way to know. */
    private static bool $readingStoredRow = false;

    /**
     * A stored row, read back for display or for the edit form to round-trip.
     *
     * Picked by spatie/laravel-data for any Transaction, so Data::collect() over the
     * index's paginator comes through here. The flag rather than a constructor
     * parameter, which would be a DTO field the form contract then demands a control
     * for.
     */
    public static function fromModel(Transaction $row): self
    {
        self::$readingStoredRow = true;

        try {
            return self::factory()->ignoreMagicalMethod('fromModel')->from($row);
        } finally {
            self::$readingStoredRow = false;
        }
    }

    /**
     * Only the constraints the property types cannot express. spatie/laravel-data
     * derives `required` and the type checks from the constructor signature.
     */
    public static function rules()
    {
        return [
            'account_id' => ['exists:accounts,id'],

            // No rule on purpose: it is derived, so there is nothing to validate, and
            // `prohibited` would not do either since the edit form round-trips a row
            // carrying the name. A payload's value is overwritten on read.

            // Required only for categorised spending. A payment may still be
            // labelled, hence required_unless rather than prohibited_unless: the
            // settlement arithmetic never reads the column.
            'category_id' => [
                'nullable',
                'exists:categories,id',
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->requiresCategory()),
            ],

            // The column is a `date`: ISO calendar date only, no time, no locales.
            'date' => ['date_format:Y-m-d'],

            // decimal counts *decimal places*, not integer digits, so this caps the
            // scale at four and `max` then caps the magnitude at the eight digits the
            // precision leaves. A string because this is money.
            //
            // Two rules over two *different* lists, and sharing one is a trap:
            // `required_unless:<non-trades>` reads as "required unless it is not a
            // trade", which is a trade, so it demands an amount on exactly the rows
            // that must not have one. See TransactionMetaData::typesExcept() for the
            // `required_if_in` that would read better and silently never runs.
            'amount' => [
                'nullable',
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => $t->derivesAmount()),
                'prohibited_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->derivesAmount()),
                'decimal:0,'.TransactionMetaData::AMOUNT_SCALE,
                'min:0',
                'max:'.TransactionMetaData::MAX_AMOUNT,
            ],

            // Required for every type, which is why it needs no conditional. `merchant`
            // used to carry this for a charge alone; see TransactionMetaData.
            'description' => ['required', 'max:255'],

            // No membership rule -- Currency is the type, so spatie derives it. Not
            // narrowed to the account's ccy: accommodating a difference is what
            // card_amount is for.
            //
            // Required, because nested rules never run on a missing key -- a trade with
            // no meta_data at all would ask for nothing and reach a NOT NULL column null.
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
     * Derived from the enum so a hand-written list cannot quietly stop matching --
     * and here that would fail open.
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
     * Assignment rather than `??=`: the derived figure is the only correct one.
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
     * A charge in USD on an HKD card stores 100 and contributes 780 to what the card
     * owes, and the difference is not derivable from anything this app holds. Required
     * rather than defaulting to `amount`, because that fallback is silent and a quietly
     * wrong statement is worse than one that refuses to be recorded. Only a charge: a
     * payment is in the card's currency by definition.
     */
    private function guardCardAmount(?Account $account): void
    {
        if ($account === null || $this->type !== TransactionType::Charge) {
            return;
        }

        if ($this->ccy->value === $account->ccy) {
            // Refused rather than ignored: CardStatement prefers card_amount over
            // amount, so a stale figure left over from another currency would
            // silently replace the real amount in what the card owes.
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
     * Only a charge. A payment's due date names the statement it settles -- normally
     * the earliest unpaid -- which is a question about outstanding balances rather
     * than one derivable from the date.
     *
     * Into a gap only: a payload that already carries a period keeps it. The bag is
     * created if there was none, since meta_data is required only for a trade and
     * skipping it would drop the charge out of its statement's figure with nothing
     * reporting a problem.
     */
    private function deriveDueDate(?Account $account): void
    {
        if ($this->type !== TransactionType::Charge) {
            return;
        }

        if ($this->meta_data?->due_date !== null) {
            return;
        }

        $dueDate = $this->periodFor($account);

        if ($dueDate === null) {
            return;
        }

        $this->meta_data ??= new TransactionMetaData;
        $this->meta_data->due_date = $dueDate;
    }

    /**
     * Put a charge in the statement period its date falls in, overwriting whichever
     * period the payload carried.
     *
     * Called on the way to an update and not from the constructor, because whether
     * the period moves is a question about the stored row, which the constructor has
     * not got.
     *
     * Overwritten rather than kept, as in deriveAmount(): the period a date falls in is
     * the only correct one. A charge whose card no longer has terms keeps the period it
     * was recorded in, since clearing the key would drop the row out of the statement
     * it belongs to.
     */
    public function placeChargeInItsPeriod(?Account $account, Transaction $charge): void
    {
        if ($this->type !== TransactionType::Charge) {
            return;
        }

        // Only when one of the two things a period comes from has moved: the date,
        // which chooses the cycle, or the account, whose terms that cycle is read from.
        // A charge's statement is a fact about the day it was made and the terms in
        // force then, so an edit touching neither has said nothing about the period --
        // and re-deriving on that evidence would refuse a description fix on a charge
        // whose statement is settled, or re-bill a year of history because somebody
        // edited the card's statement day last week.
        if ($this->date === $charge->date && $this->account_id === $charge->account_id) {
            return;
        }

        $dueDate = $this->periodFor($account);

        if ($dueDate === null) {
            return;
        }

        $this->guardPeriodCanMove($account, $charge, $dueDate);

        $this->meta_data ??= new TransactionMetaData;
        $this->meta_data->due_date = $dueDate;
    }

    /**
     * Refuse a new charge filed under a statement that has been settled.
     *
     * The harm guardPeriodCanMove() refuses for a move, arriving by the other door: a
     * paid bill owes money again, and the panel shows a figure nobody can account for.
     * Whatever the charge's status, since a pending one counts the moment it posts, and
     * posting it is an edit touching neither the date nor the account, so nothing would
     * look again then.
     *
     * Read off the bag rather than recomputed, because the bag is what the row will be
     * filed under -- a period the payload supplied included. Called from store() for
     * the reason placeChargeInItsPeriod() is called from update().
     */
    public function guardNewChargePeriod(?Account $account): void
    {
        if ($account === null || $this->type !== TransactionType::Charge) {
            return;
        }

        $dueDate = $this->meta_data?->due_date;

        if ($dueDate === null) {
            return;
        }

        if (CardStatement::forAccount($account)->firstWhere('dueDate', $dueDate)?->isSettled()) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'The statement due %s has been settled, so this charge cannot be added to it. '
                        .'Delete the payment that settled it, record the charge, and settle it again.',
                    $dueDate
                ),
            ]);
        }
    }

    /**
     * The statement period this charge's date falls in, or null when there is none.
     *
     * Null for two reasons, and both mean the same thing to every caller: the card has
     * no statement day to count a cycle from, or the date is not a date the `date` rule
     * would accept. Throwing a parse error on the second would show the user an
     * exception where the field error belongs.
     */
    private function periodFor(?Account $account): ?string
    {
        $cycle = $account === null ? null : CardStatementCycle::fromMeta($account->meta?->meta);

        if ($cycle === null) {
            return null;
        }

        try {
            $charge = Carbon::createFromFormat('Y-m-d', $this->date);
        } catch (Throwable) {
            return null;
        }

        return $cycle->dueDateFor($charge)->toDateString();
    }

    /**
     * Refuse to move a charge out of, or into, a statement that has been settled.
     *
     * A settled period is a bill that has been paid, and its figures are the record of
     * that bill: the charges it covered and the payment that closed it. Re-dating a
     * charge out of one leaves it showing a credit against money already handed over;
     * re-dating one into one makes a paid bill owing money again. Both are silent --
     * the panel would just show a figure nobody could account for -- and there is no
     * un-settling, so the answer is to refuse and say what to do instead.
     *
     * Keyed on `date` rather than the bag's due_date, because that is the field the
     * user moved and the only one of the two with a control on the form to hang a
     * message off.
     */
    private function guardPeriodCanMove(?Account $account, Transaction $charge, string $dueDate): void
    {
        if ($account === null) {
            return;
        }

        // Read off the row's own bag rather than the payload's, which is what is being
        // argued with: a charge whose account changed carries a period belonging to the
        // card it came from.
        $leaving = $charge->meta?->meta['due_date'] ?? null;

        $changingCard = $charge->account_id !== $account->id;

        // Staying in the period it is in: nothing moves. Only on the same card -- two
        // cards closing on the same day share every due date, so on another card the
        // same date is another bill, and matching it here would let a charge walk out of
        // a paid statement unchecked. A charge with no period to leave still has one to
        // arrive in, so it falls through to the second check.
        if ($leaving === $dueDate && ! $changingCard) {
            return;
        }

        $statements = CardStatement::forAccount($account);

        $leavingPeriod = $statements->firstWhere('dueDate', $leaving);

        if ($changingCard) {
            $from = Account::find($charge->account_id);

            $leavingPeriod = $from === null
                ? null
                : CardStatement::forAccount($from)->firstWhere('dueDate', $leaving);
        }

        $refuse = fn (string $message) => throw ValidationException::withMessages(['date' => $message]);

        if ($leaving !== null && $leavingPeriod?->isSettled()) {
            $refuse(sprintf(
                'The statement due %s has been settled, so this charge cannot be moved out of it. '
                    .'Delete it and record it again.',
                $leaving
            ));
        }

        if ($statements->firstWhere('dueDate', $dueDate)?->isSettled()) {
            $refuse(sprintf(
                'The statement due %s has been settled, so this charge cannot be moved into it. '
                    .'Choose a date in a period that is still open.',
                $dueDate
            ));
        }
    }
}
