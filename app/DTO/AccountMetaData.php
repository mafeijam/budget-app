<?php

namespace App\DTO;

use Spatie\LaravelData\Data;

/**
 * Type-specific attributes for an account, in the JSON meta bag so that a new
 * field needs no migration.
 */
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
            // `nullable` first, and it is the only reason any of these work: the
            // validator counts null as present, so without it `integer` and
            // `between` fire on the null a blank field produces. `required_if` is
            // implicit and still runs.
            //
            // Both required for a card, because neither alone can place a charge
            // in a statement period: a term is an interval and says nothing about
            // the day its statement closes. See App\Support\CardStatementCycle.
            //
            // 1-31 for both, and not interchangeably -- one is a day of the month
            // and one a count of days. Each matches its own guard in
            // CardStatementCycle, which rejects a value outside the range rather
            // than clamping it, since 0 or 32 is a data entry error either way.
            'term_days' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],
            'statement_day' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],

            // `type` and `id` are read from the root payload even though this rule
            // sits on a nested key -- Laravel resolves a non-dotted parameter
            // against the whole request, not against the parent array. That is what
            // lets a type-specific attribute be declared here without repeating
            // what it depends on.
            //
            // Between required_if and prohibited_unless the two say the field is
            // present exactly when the account is a securities one, and the
            // prohibition is what makes a settlement cycle unrepresentable: a cycle
            // needs a cash account in the middle, and a cash account may not carry
            // the field.
            //
            // `different` is redundant with AccountData::guardSettlementAccount() --
            // a securities account is not cash, so it cannot point at itself. It is
            // here for the message, which would otherwise blame the target.
            'settlement_account_id' => [
                'nullable',
                'required_if:type,security',
                'prohibited_unless:type,security',
                'integer',
                'exists:accounts,id',
                'different:id',
            ],
        ];
    }

    public static function attributes()
    {
        return [
            // Each entry needed: the rules message is the only thing a user sees,
            // and Laravel's snake->sentence casing would render `term_days` as
            // "term days" and `settlement_account_id` as "settlement account id"
            // on their own.
            'term_days' => 'payment term',
            'statement_day' => 'statement day',
            'settlement_account_id' => 'settlement account',
        ];
    }
}
