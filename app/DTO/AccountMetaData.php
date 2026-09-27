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
            // `nullable` first, and the only reason any of these work: the validator
            // counts null as present, so without it `integer` and `between` fire on the
            // null a blank field produces. `required_if` is implicit and runs.
            //
            // Both required for a card: a term is an interval and says nothing about
            // the day the statement closes, so neither alone can place a charge. The
            // shared 1-31 range means different things -- a day of the month, and a
            // count of days -- and CardStatementCycle rejects a value outside it
            // rather than clamping.
            'term_days' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],
            'statement_day' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],

            // `type` and `id` are read from the root payload even though this rule sits
            // on a nested key -- Laravel resolves a non-dotted parameter against the
            // whole request, not the parent array. That is what lets a type-specific
            // attribute be declared here without repeating what it depends on.
            //
            // required_if plus prohibited_unless say the field is present exactly when
            // the account may have one: required for a securities account, whose trades
            // mean nothing without a bank to settle into; merely permitted for a card,
            // usable before the user has said where they pay it from. A cash account is
            // prohibited, and that is the point -- the account settled *into* may not
            // name a target.
            //
            // `different` is redundant with AccountData::guardSettlementAccount(), and
            // is here only so the message blames the target.
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
            // Needed: the rules message is the only thing a user sees, and Laravel's
            // snake->sentence casing would render these as "term days", "statement
            // day", "settlement account id".
            'term_days' => 'payment term',
            'statement_day' => 'statement day',
            'settlement_account_id' => 'settlement account',
        ];
    }
}
