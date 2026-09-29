<?php

namespace App\DTO;

use Spatie\LaravelData\Data;

/** Type-specific account attributes, in the meta bag so a new field needs no migration. */
class AccountMetaData extends Data
{
    public function __construct(
        public ?int $term_days,
        public ?int $statement_day,

        // Securities accounts: the cash account the brokerage settles through.
        public ?int $settlement_account_id,
    ) {}

    public static function rules()
    {
        return [
            // `nullable` first: the validator counts null as present, so a blank field
            // would otherwise fail `integer`. A card needs both to place a charge.
            'term_days' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],
            'statement_day' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],

            // `type` and `id` resolve against the root payload, not this nested array.
            // `different` is the only check that stops a card settling from itself.
            'settlement_account_id' => [
                'nullable',
                'required_if:type,security',
                'prohibited_unless:type,security,card',
                'integer',
                'exists:accounts,id',
                'different:id',
            ],
        ];
    }

    public static function attributes()
    {
        return [
            'term_days' => 'payment term',
            'statement_day' => 'statement day',
            'settlement_account_id' => 'settlement account',
        ];
    }
}
