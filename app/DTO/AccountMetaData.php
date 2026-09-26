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
        // Days between the statement closing and its falling due, not a day of
        // the month -- so a card closing on the 25th with a 15-day term is paid
        // in the middle of the following month. Typed ?int, which a day of month
        // also is; the two are told apart by statement_day below.
        public ?int $due,

        // Day of month the statement closes. Required alongside `due`, because a
        // term is only an interval and says nothing about the day it runs from.
        // See App\Support\CardStatementCycle.
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
            // 1-31 for both, but for different reasons and to match two different
            // guards, so they must not be collapsed into one: a statement day is a
            // day of month and a term is a number of days. Both guards reject
            // rather than clamp, since a 0 or a 32 is a data entry error either way.
            'due' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],
            'statement_day' => ['nullable', 'required_if:type,card', 'integer', 'between:1,31'],
        ];
    }

    public static function attributes()
    {
        return [
            // "payment term" rather than "due day", which now reads as a day of
            // the month: the message is the only thing a user sees when the
            // number they typed is out of range.
            'due' => 'payment term',
            'statement_day' => 'statement day',
        ];
    }
}
