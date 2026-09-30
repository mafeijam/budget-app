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
 * Anything needing the account row is a constructor check throwing ValidationException,
 * so it reaches the form as a field error rather than a 500.
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

        // Null only because a trade derives its own.
        public ?string $amount,
        public Currency $ccy,

        public ?TransactionStatus $status,

        public ?TransactionMetaData $meta_data,
        public ?Carbon $created_at,

        // Server-owned, from the account row. Last so they can take a default.
        public ?string $account_name = null,
        public ?string $account_ccy = null,
        public ?string $account_type = null,
    ) {
        $this->created_at ??= now();
        $this->status ??= TransactionStatus::Posted;

        // A stored row is shown as stored: an account edited since would make the
        // guards throw on rows that were valid when written.
        if (self::$readingStoredRow) {
            return;
        }

        $account = Account::find($this->account_id);

        $this->account_name = $account?->name;
        $this->account_ccy = $account?->ccy;
        $this->account_type = $account?->type;

        // Dropped rather than refused: a rule cannot tell round-tripped links from forged.
        if ($this->meta_data !== null) {
            foreach (self::SERVER_LINKS as $key) {
                $this->meta_data->{$key} = null;
            }
        }

        $this->guardAccountType($account);
        $this->guardTradeCurrency($account);
        $this->guardDividendBrokerage($account);
        $this->guardCardAmount($account);
        $this->deriveAmount();
        $this->deriveDueDate($account);
    }

    /** Set while fromModel() runs; the constructor has no other way to know. */
    private static bool $readingStoredRow = false;

    /** A flag, not a constructor parameter, which the form contract would demand a control for. */
    public static function fromModel(Transaction $row): self
    {
        self::$readingStoredRow = true;

        try {
            return self::factory()->ignoreMagicalMethod('fromModel')->from($row);
        } finally {
            self::$readingStoredRow = false;
        }
    }

    /** The row, its bag and its cash side. The caller owns the database transaction. */
    public function write(): Transaction
    {
        // Only the fillable columns, not trusting $fillable to drop the rest: a seeder runs
        // unguarded, and account_name would reach an insert as a column that does not exist.
        $transaction = Transaction::create(Arr::only($this->toArray(), (new Transaction)->getFillable()));

        // Nulls dropped, falsy kept: filter() would drop a fee of '0'.
        $meta = collect($this->meta_data?->all())->filter(fn ($value) => $value !== null);

        if ($meta->isNotEmpty()) {
            $transaction->meta()->create([
                'meta' => $meta,
            ]);
        }

        TradeCash::sync($transaction);

        return $transaction;
    }

    public static function rules()
    {
        return [
            'account_id' => ['exists:accounts,id'],

            // required_unless rather than prohibited_unless: a payment may still be labelled.
            'category_id' => [
                'nullable',
                'exists:categories,id',
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->requiresCategory()),
            ],

            'date' => ['date_format:Y-m-d'],

            // Two different lists on purpose: sharing one demands an amount on exactly
            // the rows that must not have one.
            'amount' => [
                'nullable',
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => $t->derivesAmount()),
                'prohibited_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->derivesAmount()),
                'decimal:0,'.TransactionMetaData::AMOUNT_SCALE,
                'min:0',
                'max:'.TransactionMetaData::MAX_AMOUNT,
            ],

            'description' => ['required', 'max:255'],

            // Nested rules never run on a missing key, so a trade without it would
            // reach a NOT NULL column null.
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

    /** required_unless's own message lists the types that do not need a category. */
    public static function messages()
    {
        return [
            'category_id.required_unless' => 'A charge is money spent, so it needs a category.',
        ];
    }

    private static function typesWhere(callable $predicate): string
    {
        return collect(TransactionType::cases())
            ->filter($predicate)
            ->map(fn (TransactionType $type) => $type->value)
            ->implode(',');
    }

    /** Otherwise positions would sum USD with HKD. */
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

    /** The brokerage a dividend names must settle into the bank it is paid into. */
    private function guardDividendBrokerage(?Account $account): void
    {
        $brokerageId = $this->meta_data?->brokerage_account_id;

        if ($account === null || $brokerageId === null || $this->type !== TransactionType::Dividend) {
            return;
        }

        $brokerage = Account::with('meta')->find($brokerageId);

        if ($brokerage?->type === AccountType::Security->value
            && $brokerage->settlementAccount()?->id === $account->id) {
            return;
        }

        throw ValidationException::withMessages([
            'meta_data.brokerage_account_id' => sprintf(
                'A dividend on [%s] names a brokerage that settles into it, and %s does not.',
                $account->name,
                $brokerage === null ? 'that account does not exist, so it' : "[{$brokerage->name}]"
            ),
        ]);
    }

    private function guardAccountType(?Account $account): void
    {
        // `exists:accounts,id` reports that, and throwing here would mask it.
        if ($account === null) {
            return;
        }

        if (! $this->type->isAllowedFor(AccountType::from($account->type))) {
            throw ValidationException::withMessages([
                'type' => "A {$this->type->value} cannot be recorded on a {$account->type} account.",
            ]);
        }
    }

    /** Assignment rather than `??=`: the derived figure is the only correct one. */
    private function deriveAmount(): void
    {
        if (! $this->type->derivesAmount()) {
            return;
        }

        $this->amount = $this->meta_data?->derivedAmount($this->type);
    }

    /** Required rather than defaulting to `amount`, which would silently misstate the card. */
    private function guardCardAmount(?Account $account): void
    {
        if ($account === null || $this->type !== TransactionType::Charge) {
            return;
        }

        if ($this->ccy->value === $account->ccy) {
            // Refused rather than ignored: CardStatement prefers card_amount over amount.
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

    /** Only a charge: a payment's due date names the statement it settles. */
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

    /** Not from the constructor: it needs the stored row. */
    public function placeChargeInItsPeriod(?Account $account, Transaction $charge): void
    {
        if ($this->type !== TransactionType::Charge) {
            return;
        }

        // Otherwise a changed statement day would re-bill a year of history.
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

    /** The bag keys only settle() writes. */
    private const SERVER_LINKS = ['paired_transaction_id', 'settled_by'];

    /** Without this, any edit to a settled row cuts its links, since update() replaces the bag. */
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

    /** The fields CardStatement::forAccount() reads, keyed to their words. */
    public const FIGURES = [
        'account_id' => 'account',
        'type' => 'type',
        'amount' => 'amount',
        'ccy' => 'currency',
        'status' => 'status',
        'meta_data.card_amount' => 'amount in the card\'s currency',
    ];

    private const LOCKABLE = self::FIGURES + ['date' => 'date'];

    /**
     * `refusal` is a format taking the changed field's words.
     *
     * @param  Collection<int, CardStatement>|null  $cardPeriods  null off a card
     * @return array{fields: list<string>, message: string, refusal: string}|null
     */
    public static function figureLock(Transaction $row, ?Collection $cardPeriods, ?Transaction $partner): ?array
    {
        $dueDate = $row->meta?->meta['due_date'] ?? null;
        $isCharge = $row->type === TransactionType::Charge->value;

        $figures = $isCharge ? self::FIGURES : Arr::except(self::FIGURES, 'meta_data.card_amount');
        $fields = array_keys($figures);
        $words = Arr::join(array_values($figures), ', ', ' and ');

        if ($dueDate !== null && $cardPeriods?->firstWhere('dueDate', $dueDate)?->isClosed()) {
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

        // A trade's cash side is written from it and follows the edit.
        if ($partner !== null && TradeCash::isTrade($row)) {
            return null;
        }

        if (TradeCash::isTrade($partner)) {
            $cash = ['account_id' => 'account', 'type' => 'type', 'date' => 'date']
                + Arr::except(self::FIGURES, ['account_id', 'type', 'meta_data.card_amount']);

            $other = TradeCash::describe($partner);
            $words = Arr::join(array_values($cash), ', ', ' and ');

            return [
                'fields' => array_keys($cash),
                'message' => "This {$row->type} is the cash side of the trade {$other}, so its "
                    ."{$words} follow the trade. Edit the trade instead.",
                'refusal' => "This {$row->type} is the cash side of the trade {$other}, so its %s "
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

    public function guardFigures(Transaction $row): void
    {
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
     * Amounts compare as decimals: the column reads back '120.0000' for a sent '120'.
     *
     * @param  list<string>  $fields
     * @return array{0: string, 1: string}|null
     */
    private function changedFigure(Transaction $row, array $fields): ?array
    {
        foreach ($fields as $field) {
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

    /** Only a shortfall this change causes: refusing an existing one would block its fix. */
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

    /** Whatever the status: a pending charge counts once posted, and nothing checks again then. */
    public function guardNewChargePeriod(?Account $account): void
    {
        if ($account === null || $this->type !== TransactionType::Charge) {
            return;
        }

        $dueDate = $this->meta_data?->due_date;

        if ($dueDate === null) {
            return;
        }

        if (CardStatement::forAccount($account)->firstWhere('dueDate', $dueDate)?->isClosed()) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'The statement due %s has been settled, so this charge cannot be added to it. '
                        .'Delete the payment that settled it, record the charge, and settle it again.',
                    $dueDate
                ),
            ]);
        }
    }

    /** Null on a bad date too: the `date` rule reports that as a field error. */
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

    /** Keyed on `date`, the only one of date and due_date with a control. */
    private function guardPeriodCanMove(?Account $account, Transaction $charge, string $dueDate): void
    {
        if ($account === null) {
            return;
        }

        // The stored row's bag, not the payload's.
        $leaving = $charge->meta?->meta['due_date'] ?? null;

        $changingCard = $charge->account_id !== $account->id;

        // Same card only: two cards closing on one day share every due date.
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

        if ($leaving !== null && $leavingPeriod?->isClosed()) {
            $refuse(sprintf(
                'The statement due %s has been settled, so this charge cannot be moved out of it. '
                    .'Delete the payment that settled it, move the charge, and settle it again.',
                $leaving
            ));
        }

        if ($statements->firstWhere('dueDate', $dueDate)?->isClosed()) {
            $refuse(sprintf(
                'The statement due %s has been settled, so this charge cannot be moved into it. '
                    .'Choose a date in a period that is still open.',
                $dueDate
            ));
        }
    }
}
