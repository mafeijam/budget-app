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
    ) {}

    public static function rules()
    {
        return [
            // `nullable` first, and it is the only reason either key works: the
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
        ];
    }

    public static function attributes()
    {
        return [
            // Both entries needed: the rules message is the only thing a user
            // sees, and Laravel's snake->sentence casing would render
            // `term_days` as "term days" on its own.
            'term_days' => 'payment term',
            'statement_day' => 'statement day',
        ];
    }
}
