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
use App\Support\Positions;
use App\Support\TradeCash;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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

        // The owning account's name and currency: the model's accessors on a read, the
        // account row on a write. Not a client's to decide -- rules() has no rule for
        // either, and says why. Last and defaulted because an optional parameter ahead
        // of the required ones gets no default at all.
        //
        // The currency is the one card_amount is stated in, which the row's own ccy is
        // not whenever card_amount exists at all.
        public ?string $account_name = null,
        public ?string $account_ccy = null,
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
        $this->account_ccy = $account?->ccy;

        // Dropped rather than refused: the edit form round-trips the row's real links,
        // and a rule cannot tell those from forged ones. keepLinksOf() restores them.
        if ($this->meta_data !== null) {
            foreach (self::SERVER_LINKS as $key) {
                $this->meta_data->{$key} = null;
            }
        }

        $this->guardAccountType($account);
        $this->guardTradeCurrency($account);
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
     * Refuse a row on a brokerage in any currency but the brokerage's own.
     *
     * One currency per broker is the model: a brokerage settles into one cash account,
     * which Account::guardSettledFrom() holds to the same currency, so a trade in
     * another would take money out of an account in the wrong currency -- and the
     * positions it adds to would sum USD with HKD. A broker trading both is two
     * accounts here.
     */
    private function guardTradeCurrency(?Account $account): void
    {
        if ($account === null || $account->type !== AccountType::Security->value) {
            return;
        }

        if ($this->ccy->value !== $account->ccy) {
            throw ValidationException::withMessages([
                'ccy' => sprintf(
                    '[%s] trades in %s, so this %s must be in %s too. A broker trading in '
                        .'several currencies is one account per currency.',
                    $account->name,
                    $account->ccy,
                    $this->type->value,
                    $account->ccy
                ),
            ]);
        }
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
     * The bag keys only settle() writes, linking a row to another: the other half of a
     * settlement, and on a charge the payment that settled it.
     */
    private const SERVER_LINKS = ['paired_transaction_id', 'settled_by'];

    /**
     * Carry the stored row's links into the bag that replaces its own.
     *
     * update() replaces the bag outright, and the constructor has dropped the payload's
     * links, so without this any edit to a settled row -- a description fix -- cuts
     * them: the pair comes apart and destroy() then deletes one row of two, and a paid
     * charge forgets which payment paid it.
     */
    public function keepLinksOf(Transaction $row): void
    {
        foreach (self::SERVER_LINKS as $key) {
            $stored = $row->meta?->meta[$key] ?? null;

            if ($stored === null) {
                continue;
            }

            $this->meta_data ??= new TransactionMetaData;
            $this->meta_data->{$key} = $stored;
        }
    }

    /**
     * The fields a statement's figures, and a settlement's agreement with its other
     * half, are built from: the error key, and the words for it.
     *
     * The ones CardStatement::forAccount() reads, and a second statement of them the
     * query cannot share. Description and category are absent so they stay editable; a
     * charge's date is guardPeriodCanMove()'s.
     */
    public const FIGURES = [
        'account_id' => 'account',
        'type' => 'type',
        'amount' => 'amount',
        'ccy' => 'currency',
        'status' => 'status',
        'meta_data.card_amount' => 'amount in the card\'s currency',
    ];

    /**
     * Every field a lock can name, with its words: FIGURES, and a paid charge's date.
     *
     * The date is not a figure -- a payment's does not decide its period, and a
     * settlement's two halves may disagree about the day -- so it joins a lock only for
     * a charge in a settled statement, where it is what chose the statement.
     */
    private const LOCKABLE = self::FIGURES + ['date' => 'date'];

    /**
     * Why a stored row's figures are fixed, or null when they are not.
     *
     * One answer for the two places that ask: guardFigures() refusing a save, and the
     * edit form saying so before the user tries. Two locks, the first winning when both
     * hold:
     *
     * A row in a settled statement. Its figure changing leaves the paid bill owing or
     * in credit -- a charge's amount corrected, a payment marked pending -- as silently
     * as moving it would. A charge's date is fixed with them, even within its period:
     * the edit form disables what is locked, and a date that may move only between two
     * days nobody can see is not something a disabled control can say.
     *
     * One half of a card settlement. The two rows are one movement of money, and the
     * transfer has no due date for the first lock to see: correcting its amount has the
     * bank say one figure left and the card say another arrived. On the pairing rather
     * than the period, because the halves have to agree whether or not the statement is
     * still settled. A row whose partner has gone is half of nothing and edits freely,
     * as destroy() already deletes it alone.
     *
     * `refusal` is a format taking the changed field's words; `message` is the form's.
     *
     * @param  Collection<int, CardStatement>|null  $cardPeriods  the periods of the row's
     *                                                            card, null off a card
     * @return array{fields: list<string>, message: string, refusal: string}|null
     */
    public static function figureLock(Transaction $row, ?Collection $cardPeriods, ?Transaction $partner): ?array
    {
        $dueDate = $row->meta?->meta['due_date'] ?? null;
        $isCharge = $row->type === TransactionType::Charge->value;

        // Only a charge has a card-currency figure, so only a charge names it as fixed.
        $figures = $isCharge ? self::FIGURES : Arr::except(self::FIGURES, 'meta_data.card_amount');
        $fields = array_keys($figures);
        $words = Arr::join(array_values($figures), ', ', ' and ');

        if ($dueDate !== null && $cardPeriods?->firstWhere('dueDate', $dueDate)?->isSettled()) {
            if ($isCharge) {
                $figures = array_slice($figures, 0, 2) + ['date' => 'date'] + array_slice($figures, 2);
                $fields = array_keys($figures);
                $words = Arr::join(array_values($figures), ', ', ' and ');
            }

            $remedy = $row->type === TransactionType::Payment->value
                ? 'Delete this payment and settle the statement again.'
                : 'Delete the payment that settled it, make the change, and settle it again.';

            return [
                'fields' => $fields,
                'message' => sprintf(
                    'The statement due %s has been settled, so this %s\'s %s are fixed. %s',
                    $dueDate,
                    $row->type,
                    $words,
                    $remedy
                ),
                'refusal' => "The statement due {$dueDate} has been settled, so this {$row->type}'s %s "
                    ."cannot be changed. {$remedy}",
            ];
        }

        // A trade edits freely: its cash is written from it and follows the edit.
        if ($partner !== null && TradeCash::isTrade($row)) {
            return null;
        }

        // The cash side of a trade: every figure is the trade's, and the date too.
        if (TradeCash::isTrade($partner)) {
            $cash = ['account_id' => 'account', 'type' => 'type', 'date' => 'date']
                + Arr::except(self::FIGURES, ['account_id', 'type', 'meta_data.card_amount']);
            $trade = TradeCash::describe($partner);

            return [
                'fields' => array_keys($cash),
                'message' => "This {$row->type} is the cash side of the trade {$trade}, so its "
                    .Arr::join(array_values($cash), ', ', ' and ').' follow the trade. Edit the trade instead.',
                'refusal' => "This {$row->type} is the cash side of the trade {$trade}, so its %s "
                    .'cannot be changed here. Edit the trade instead.',
            ];
        }

        if ($partner !== null) {
            $remedy = 'Delete the settlement and settle the statement again.';

            return [
                'fields' => $fields,
                'message' => "This {$row->type} is one half of a card settlement, so its {$words} "
                    ."are fixed to match the other half. {$remedy}",
                'refusal' => "This {$row->type} is one half of a card settlement, so its %s cannot "
                    ."be changed on its own. {$remedy}",
            ];
        }

        return null;
    }

    /**
     * Refuse an edit to a figure figureLock() says is fixed.
     *
     * Keyed on the first field that changed, which is the control the user touched.
     */
    public function guardFigures(Transaction $row): void
    {
        // Checked before anything is read, since nearly every edit changes none.
        if ($this->changedFigure($row, array_keys(self::LOCKABLE)) === null) {
            return;
        }

        $card = $row->account;
        $paired = $row->meta?->meta['paired_transaction_id'] ?? null;

        $lock = self::figureLock(
            $row,
            $card?->type === AccountType::Card->value ? CardStatement::forAccount($card) : null,
            $paired === null ? null : Transaction::with(['meta', 'account'])->find($paired),
        );

        $changed = $lock === null ? null : $this->changedFigure($row, $lock['fields']);

        if ($changed === null) {
            return;
        }

        [$field, $label] = $changed;

        throw ValidationException::withMessages([$field => sprintf($lock['refusal'], $label)]);
    }

    /**
     * The first of these LOCKABLE fields this payload changes, as the error key and the
     * words for it, or null when it changes none.
     *
     * Amounts compared as decimals: the column reads back '120.0000' and a form may
     * send '120', which is no change.
     *
     * @param  list<string>  $fields
     * @return array{0: string, 1: string}|null
     */
    private function changedFigure(Transaction $row, array $fields): ?array
    {
        foreach ($fields as $field) {
            // A field added to LOCKABLE without a line here is an UnhandledMatchError,
            // not a figure quietly never compared.
            [$sent, $stored] = match ($field) {
                'account_id' => [(string) $this->account_id, (string) $row->account_id],
                'type' => [$this->type->value, $row->type],
                'date' => [$this->date, $row->date],
                'amount' => [$this->amount, $row->amount],
                'ccy' => [$this->ccy->value, $row->ccy],
                'status' => [$this->status->value, $row->status],
                'meta_data.card_amount' => [$this->meta_data?->card_amount, $row->meta?->meta['card_amount'] ?? null],
            };

            $decimal = in_array($field, ['amount', 'meta_data.card_amount'], true)
                && $sent !== null
                && $stored !== null;

            $differs = $decimal
                ? ! BigDecimal::of($sent)->isEqualTo(BigDecimal::of($stored))
                : $sent !== $stored;

            if ($differs) {
                return [$field, self::LOCKABLE[$field]];
            }
        }

        return null;
    }

    /**
     * Refuse a trade that would leave a brokerage selling shares it does not hold.
     *
     * Asked of the brokerage this row is written to and, when an edit moves a trade off
     * one, of the brokerage it leaves: taking a buy away can strand a sell as surely as
     * adding a sell can. The trades are replayed with this change in place of the row
     * it replaces, so a sell is checked against what was held on its own day.
     *
     * Only a shortfall this change causes. One that was already there -- data written
     * before this rule existed -- is not the edit's fault, and refusing on it would block
     * the edit that might be fixing it.
     *
     * Called from store() and update() for the reason placeChargeInItsPeriod() is: it
     * needs the stored row, which the constructor has not got.
     */
    public function guardHoldings(?Transaction $replacing = null): void
    {
        $isTrade = fn (string $type) => TransactionType::from($type)->derivesAmount();

        if (! $isTrade($this->type->value) && ($replacing === null || ! $isTrade($replacing->type))) {
            return;
        }

        $accounts = array_unique(array_filter([$this->account_id, $replacing?->account_id]));

        foreach ($accounts as $accountId) {
            $broker = Account::find($accountId);

            if ($broker === null || $broker->type !== AccountType::Security->value) {
                continue;
            }

            $before = Positions::tradesOf($broker);

            $after = array_values(array_filter($before, fn (array $trade) => $trade['id'] !== $replacing?->id));

            if ($accountId === $this->account_id && $this->type->derivesAmount()) {
                // A new row sorts after everything already on its day, as it was entered
                // after them.
                $trade = Positions::trade(
                    $replacing?->id ?? PHP_INT_MAX,
                    $this->date,
                    $this->type->value,
                    $this->meta_data?->all() ?? []
                );

                if ($trade !== null) {
                    $after[] = $trade;
                }
            }

            $short = Positions::shortfall($after);

            if ($short === null || $short === Positions::shortfall($before)) {
                continue;
            }

            throw ValidationException::withMessages([
                'meta_data.quantity' => sprintf(
                    'That leaves a sell of %s %s on %s with only %s held that day. A sell cannot '
                        .'be more than is held.',
                    Positions::plain($short['selling']),
                    $short['symbol'],
                    $short['date'],
                    Positions::plain($short['held'])
                ),
            ]);
        }
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
     * the panel would just show a figure nobody could account for -- so the answer is
     * to refuse, and to name the one way to reopen a period: deleting the payment that
     * closed it. Not "delete the charge", which deleteRefusal() turns down for the
     * same reason this does.
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
                    .'Delete the payment that settled it, move the charge, and settle it again.',
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
