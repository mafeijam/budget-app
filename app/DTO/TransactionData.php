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
 * hence conditional rather than uniform. Payload constraints in rules(), anything
 * needing the account row in the constructor. amount is a positive magnitude, its
 * direction coming from the account and transaction types together.
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

        // The owning account's name, read off the model rather than the accounts
        // table so a list costs no extra query per row. Not something a client
        // decides; see rules() for why it is not `prohibited`.
        //
        // Last, and defaulted, because no caller has it to hand -- an optional
        // parameter ahead of the required ones would not get its default at all.
        public ?string $account_name = null,
    ) {
        $this->created_at ??= now();
        $this->status ??= TransactionStatus::Posted;

        // One account read serves the pairing check, the due date and the name.
        $account = Account::find($this->account_id);

        $this->account_name = $account?->name;

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

            // account_name carries no rule on purpose: it is derived, so there is
            // nothing to validate -- but it cannot be `prohibited` either, because
            // the edit form round-trips a whole table row that now carries the name.
            // Whatever a payload sends is overwritten when the row is read back, so
            // it is inert rather than trusted.

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
            // precision leaves. A string because this is money -- widen the rules
            // rather than retyping it.
            //
            // Two rules over two *different* lists, and sharing one is a trap:
            // `required_unless:<non-trades>` reads as "required unless it is not a
            // trade", which is a trade, so it demands an amount on exactly the rows
            // that must not have one. There is no `required_if_in` or
            // `prohibited_if_in` in this Laravel -- both are accepted into the array
            // and then never run, failing silently.
            'amount' => [
                'nullable',
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => $t->derivesAmount()),
                'prohibited_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->derivesAmount()),
                'decimal:0,'.TransactionMetaData::AMOUNT_SCALE,
                'min:0',
                'max:'.TransactionMetaData::MAX_AMOUNT,
            ],

            // Required for every type, which is why it needs no conditional. A row
            // nobody can name is a row the list shows as a type and an amount and
            // nothing else, and `merchant` used to carry that weight for a charge
            // alone -- see the note on TransactionMetaData for why it is gone.
            'description' => ['required', 'max:255'],

            // No membership rule -- Currency is the type, so spatie derives it. Not
            // narrowed to the account's ccy, since accommodating a difference is
            // what card_amount is for; not widened to all of ISO 4217, which would
            // record a code no account can hold and no dropdown offers.
            //
            // Required, because without it a trade with no meta_data at all passes:
            // nested rules never run on a missing key, so nothing asks for the
            // numbers the amount is derived from and the row reaches a NOT NULL
            // column null.
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
     * hand-written list quietly stops matching, and here that fails open.
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
     * A constructor check rather than a rule, because what is legal depends on the
     * account row -- so it holds whether or not the caller called validate(). A
     * ValidationException, so it reaches the form as a field error, not a 500.
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
     * Assignment rather than `??=`: the derived figure is the only correct one, so a
     * supplied amount is overwritten even unvalidated.
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
     * owes, and the difference between those two numbers is not derivable from
     * anything this app holds -- so the user types it. Required rather than defaulting
     * to `amount`, because that fallback is silent: a forgotten figure would
     * contribute the raw amount in the wrong currency, and a quietly wrong statement
     * is worse than one that refuses to be recorded. A constructor check for the same
     * reason guardAccountType() is one -- the account row is the other half of the
     * comparison and a rule cannot see it.
     *
     * Only a charge. A payment is in the card's currency by definition, and the bank
     * it is paid from must be in the same one. See
     * AccountData::guardSettlementAccount().
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
     * Only a charge. A payment's due date names the statement it settles, normally
     * the earliest unpaid -- a question about outstanding balances that belongs in
     * the controller. A card with no statement day yields no due date rather than
     * one counted from the payment term alone, which would be a whole cycle out.
     *
     * Into a gap only. A payload that already carries one keeps it, which is the
     * door an edit used to walk through and the reason there is a second method for
     * it; see placeChargeInItsPeriod().
     *
     * The bag is created if the payload carried none. Not tidiness: meta_data is
     * required for a trade and optional otherwise, so a charge that sends no bag is
     * valid, and skipping it would drop the charge out of its statement's figure with
     * nothing reporting a problem.
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
     * Put a charge in the statement period the date on the payload puts it in,
     * overwriting whichever period the payload carried.
     *
     * Called by the controller on the way to an update, and deliberately not from the
     * constructor: that also runs when a stored row is read back, through
     * TransactionData::collect. Re-deriving on a read would show a period the statement
     * panel does not group by the moment someone edits a card's statement day -- two
     * views of one row, disagreeing, and neither of them wrong about itself. A read
     * shows what is stored; a write recomputes.
     *
     * The second door exists because the edit form round-trips a whole table row, and
     * a row's bag carries the due date the server derived last time. So every edit of
     * a charge arrived holding the bill it was already counted in, deriveDueDate()
     * stood down because the field was filled, and a corrected date left the charge in
     * a statement it no longer belonged to: the row and the panel describing different
     * bills, with the statement query grouping on exactly the key that disagreed.
     *
     * Overwritten rather than kept, as in deriveAmount(): the period a date falls in is
     * the only correct one, so a supplied figure is overruled rather than trusted. A
     * charge the card has no terms for keeps whatever period it has, because the terms
     * may have been removed since the charge was made and that charge is still a fact
     * -- and clearing the key would drop the row out of the statement it was recorded
     * in, which is a worse answer than a stale one to argue about later.
     *
     * A payment is left entirely alone. Its due date names the statement it settles,
     * and that is the one thing about a payment that is not derivable from its date.
     *
     * @param  Transaction  $charge  the row being written, so what it already says can
     *                               be compared against what the payload says.
     */
    public function placeChargeInItsPeriod(?Account $account, Transaction $charge): void
    {
        if ($this->type !== TransactionType::Charge) {
            return;
        }

        // Only when one of the two things a period is derived from has actually moved:
        // the date, which chooses the cycle, or the account, whose terms that cycle is
        // read from. A charge's statement is a fact about the day it was made and the
        // terms in force then, so an edit that touches neither has said nothing about
        // the period -- and re-deriving on that evidence would refuse a description
        // fix on a charge whose statement has been settled ("this charge cannot be
        // moved out of it", to a user who moved nothing), or re-bill a year of history
        // because somebody edited the card's statement day last week.
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
     * The statement period this charge's date falls in, or null when there is none.
     *
     * Null for two different reasons, and both mean the same thing to every caller:
     * the card has no statement day to count a cycle from, or the date is not a date
     * the `date` rule would accept. Throwing a parse error on the second would show
     * the user an exception where the field error belongs.
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
     * re-dating one into one makes a paid bill owing money again. Both are a corrupted
     * statement rather than a corrected one, this app has no way to represent the
     * difference, and both are silent -- the panel would just show a figure nobody could
     * account for. There is no un-settling either, so the answer is to refuse and say
     * what to do instead, which is the same bargain guardAccountType() makes.
     *
     * Keyed on `date` rather than on the bag's due_date, because the date is the field
     * the user moved and the only one of the two with a control on the form: due_date
     * is server-owned (FormContractTest's allowlist) and has no field to hang a message
     * off, so an error keyed there would be raised where nobody can see it.
     *
     * Both periods are read from the one query settle() reads, so the refusal is made on
     * the same figures the panel showed. The card the charge is leaving is a second
     * query, and only when the account itself changed -- the period being left behind
     * belongs to the card it was on, which need not be the one it is being written to.
     */
    private function guardPeriodCanMove(?Account $account, Transaction $charge, string $dueDate): void
    {
        if ($account === null) {
            return;
        }

        // The period the row is in now, read off its own bag rather than off the
        // payload: what the payload carries is what is being argued with, and a charge
        // whose account changed carries a period belonging to the card it came from.
        $leaving = $charge->meta?->meta['due_date'] ?? null;

        // Nothing to leave, or leaving for the period it is in: nothing moves.
        if ($leaving === null || $leaving === $dueDate) {
            return;
        }

        $statements = CardStatement::forAccount($account);

        $leavingPeriod = $statements->firstWhere('dueDate', $leaving);

        if ($charge->account_id !== $account->id) {
            $from = Account::find($charge->account_id);

            $leavingPeriod = $from === null
                ? null
                : CardStatement::forAccount($from)->firstWhere('dueDate', $leaving);
        }

        $refuse = fn (string $message) => throw ValidationException::withMessages(['date' => $message]);

        if ($leavingPeriod?->isSettled()) {
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
