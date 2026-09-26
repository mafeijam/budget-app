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
        // Day of month, not a date. Typed ?string once, which let '2026-10-01'
        // validate and store, after which every charge on the card derived a null
        // due date.
        public ?int $due,

        // Day of month. Required alongside `due`, because a charge cannot be placed
        // in a statement period from the due day alone -- see
        // App\Support\CardStatementCycle. Typed int, so a blank field must arrive
        // as null rather than an empty string.
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
            // 1-31 to match CardStatementCycle::guardDay, which rejects a day
            // outside it rather than clamping. The rule this replaces, `max:28`,
            // measured the length of a string, so '2026-10-01' passed.
            'due' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],
            'statement_day' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],
        ];
    }

    public static function attributes()
    {
        return [
            // "due day", not "due date": the form's own label used to read as a
            // calendar date and half the fixtures duly held one.
            'due' => 'due day',
            'statement_day' => 'statement day',
        ];
    }
}
