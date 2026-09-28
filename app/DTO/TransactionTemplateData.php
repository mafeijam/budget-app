<?php

namespace App\DTO;

use App\Enums\TransactionType;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * A named set of the transaction form's values.
 *
 * Deliberately shallow. A template is a shortcut to a form, not a transaction, and the
 * tempting thing -- validating the payload with TransactionData's rules, so a template
 * cannot hold values a transaction would refuse -- does not work: those rules are
 * conditional on a root `type` and an `account_id` that live in columns beside the
 * payload rather than inside it, and TransactionData's constructor derives an amount and
 * guards against the account, which is the wrong question to ask about a shortcut.
 *
 * So the payload is taken on trust and the transaction's own rules run when the template
 * is used, which is where a value that no longer fits belongs: it reaches the form as a
 * field error on the field the user has to change anyway. What is checked here is only
 * what a template cannot be without.
 */
class TransactionTemplateData extends Data
{
    /**
     * The payload keys a template keeps.
     *
     * The complement of FormContractTest's SERVER_ONLY, which is the other half of the
     * same statement: those are the paths the browser neither supplies nor reads, and
     * storing any of them would mean filling a form field the server owns -- `id` with a
     * row that is not being edited, `due_date` with one the server derives per card, the
     * settlement links with two rows that must stay paired. Kept here rather than in the
     * controller so the exclusions are named next to the payload they narrow.
     *
     * `date` is in neither list, because a template does not keep it for a reason of its
     * own: the form seeds today's date and reusing it is the point, so a template stored
     * in March must not put March back in the field in September. `account_id` and
     * `category_id` are here for the opposite reason -- they are columns, and a second
     * copy in the payload would be a second thing to keep in step.
     */
    public const KEEPS = ['type', 'description', 'amount', 'ccy', 'status'];

    /** The bag keys of the above, which the container is not named for. */
    public const KEEPS_IN_BAG = ['symbol', 'quantity', 'unit_price', 'fees', 'card_amount', 'no_cash'];

    public function __construct(
        public string $name,
        public int $account_id,
        public ?int $category_id,
        public array $payload,
    ) {}

    public static function rules()
    {
        return [
            // No `unique`. A name in use is not a mistake to refuse but a second
            // template of the same thing, and the controller appends a number to it
            // rather than making the user invent a different name.
            'name' => ['required', 'string', 'max:255'],

            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],

            'payload' => ['required', 'array'],

            // Required because a template with no type fills a form that then offers no
            // types at all, and reads as a shortcut to nothing. The enum, not a list:
            // the payload arrives as an array, so unlike TransactionData nothing
            // derives the membership for it.
            'payload.type' => ['required', Rule::enum(TransactionType::class)],
        ];
    }

    public static function attributes()
    {
        return [
            'name' => 'name',
            'account_id' => 'account',
            'category_id' => 'category',
            'payload' => 'saved values',
            'payload.type' => 'type',
        ];
    }

    /**
     * The payload narrowed to the keys a template keeps.
     *
     * Read off the incoming array rather than rebuilt, so a key that is null stays null
     * and is stored as null: TransactionData::derivedAmount() reads an absent unit_price
     * as '0' and would compute a buy's cost off a blank field it was never shown.
     */
    public function keptPayload(): array
    {
        $bag = Arr::only($this->payload['meta_data'] ?? [], self::KEEPS_IN_BAG);

        return array_merge(
            Arr::only($this->payload, self::KEEPS),
            $bag === [] ? [] : ['meta_data' => $bag]
        );
    }
}
