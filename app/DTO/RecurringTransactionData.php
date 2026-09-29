<?php

namespace App\DTO;

use App\Enums\Currency;
use App\Enums\Frequency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\RecurringTransaction;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

class RecurringTransactionData extends Data
{
    public function __construct(
        public ?int $id,
        public int $account_id,
        public ?int $category_id,
        public TransactionType $type,
        public string $description,
        public string $amount,
        public Currency $ccy,
        public ?string $card_amount,
        public Frequency $frequency,
        public string $start_date,
        public ?string $end_date,
        public bool $active,
    ) {
        if (self::$readingStoredRow) {
            return;
        }

        $this->guardAsTransaction();
    }

    /** Set while fromModel() runs, as TransactionData's is. */
    private static bool $readingStoredRow = false;

    public static function fromModel(RecurringTransaction $row): self
    {
        self::$readingStoredRow = true;

        try {
            return self::factory()->ignoreMagicalMethod('fromModel')->from($row);
        } finally {
            self::$readingStoredRow = false;
        }
    }

    public static function rules()
    {
        return [
            'account_id' => ['exists:accounts,id'],

            'category_id' => [
                'nullable',
                'exists:categories,id',
                'required_unless:type,'.self::typesWhere(fn (TransactionType $t) => ! $t->requiresCategory()),
            ],

            'type' => ['in:'.self::typesWhere(fn (TransactionType $t) => $t->canRecur())],

            'description' => ['required', 'max:255'],

            // gt rather than the transaction's min: a recurring zero writes nothing worth a row.
            'amount' => ['decimal:0,'.TransactionMetaData::AMOUNT_SCALE, 'gt:0', 'max:'.TransactionMetaData::MAX_AMOUNT],
            'card_amount' => TransactionMetaData::rules()['card_amount'],

            'start_date' => ['date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    public static function attributes()
    {
        return [
            'account_id' => 'account',
            'category_id' => 'category',
            'ccy' => 'currency',
            'card_amount' => 'amount in the card\'s currency',
            'start_date' => 'first date',
            'end_date' => 'end date',
        ];
    }

    public static function messages()
    {
        return [
            'category_id.required_unless' => 'A charge is money spent, so it needs a category.',
            'type.in' => 'Only a withdrawal, a deposit, a charge or a payment can repeat.',
        ];
    }

    /** The pending row this writes on $date, built by TransactionData so its guards run. */
    public function transactionOn(string $date): TransactionData
    {
        return new TransactionData(
            id: null,
            account_id: $this->account_id,
            category_id: $this->category_id,
            date: $date,
            type: $this->type,
            description: $this->description,
            amount: $this->amount,
            ccy: $this->ccy,
            status: TransactionStatus::Pending,
            meta_data: $this->card_amount === null ? null : new TransactionMetaData(card_amount: $this->card_amount),
            created_at: null,
        );
    }

    /**
     * Refused on save rather than on the day, when the recorder has nobody to show the
     * message to: a charge in another currency with no card amount, a type the account
     * does not take.
     */
    private function guardAsTransaction(): void
    {
        try {
            $this->transactionOn($this->start_date);
        } catch (ValidationException $e) {
            $errors = collect($e->errors())
                ->mapWithKeys(fn (array $messages, string $key) => [
                    ($key === 'meta_data.card_amount' ? 'card_amount' : $key) => $messages,
                ])
                ->all();

            throw ValidationException::withMessages($errors);
        }
    }

    private static function typesWhere(callable $predicate): string
    {
        return collect(TransactionType::cases())
            ->filter($predicate)
            ->map(fn (TransactionType $type) => $type->value)
            ->implode(',');
    }
}
