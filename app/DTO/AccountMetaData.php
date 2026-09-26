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
        // Day of month the card is paid on, not a date. Three independent things
        // already agreed on this and the field did not: CardStatementCycle
        // refuses to build a cycle unless both card terms are numeric, the
        // seeder wrote '15', and the form's input is type="number". The old
        // ?string type let a date-shaped value through validation to be stored
        // and then silently derive no due date at all -- the account saved
        // cleanly and every charge on it came out with a null due_date.
        public ?int $due,
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
            // `nullable` first, for the reason spelled out on statement_day: a
            // null counts as present to the validator, so without it `integer`
            // and `between` both fire on the null a blank form field and
            // empty() produce. `required_if` is implicit and still runs.
            //
            // The range is 1-31 to match CardStatementCycle::guardDay, which
            // rejects a day outside it rather than clamping. The rule this
            // replaces was `max:28`, which measured the *length of a string* --
            // so both '15' and '2026-10-01' passed, and a value that was not a
            // day at all was accepted, over a range that disagreed with the
            // guard behind it.
            'due' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],

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
            // Named for what it holds. "due date" is part of what made the field
            // ambiguous: the form's own label read as a calendar date, and half
            // the fixtures duly held one.
            'due' => 'due day',
            'statement_day' => 'statement day',
        ];
    }
}
