<?php

namespace App\DTO;

use Spatie\LaravelData\Data;

/**
 * Type-specific attributes for an account.
 *
 * Stored in the JSON meta bag rather than as columns, so adding a field here
 * needs no migration.
 */
class AccountMetaData extends Data
{
    public function __construct(
        public ?string $due,
        // Day of month the card's statement closes. Required alongside `due`
        // because a charge cannot be placed in a statement period from the due
        // day alone -- see App\Support\CardStatementCycle.
        //
        // Typed int, so an empty field must arrive as null rather than an empty
        // string. Both AccountData::empty() and the Vue form produce null; a
        // client sending "" would raise a TypeError in the DTO resolver, which
        // AccountController turns into a generic "error db..." flash rather than
        // a field error.
        public ?int $statement_day,
    ) {}

    public static function rules()
    {
        return [
            'due' => ['required_if:type,card', 'max:28'],
            // `nullable` is what makes this key work. A null value counts as
            // "present" to the validator, so without it `integer` and `between`
            // both fire on the null that a blank form field and empty() both
            // produce -- turning a cash account's untouched form into two
            // spurious errors. `required_if` is implicit and still runs, so a
            // card account with no statement day is still rejected.
            'statement_day' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],
        ];
    }

    public static function attributes()
    {
        return [
            'due' => 'due date',
            'statement_day' => 'statement day',
        ];
    }
}
