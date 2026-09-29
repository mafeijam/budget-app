<?php

namespace App\DTO;

use App\Enums\TransactionType;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

/**
 * A named set of the transaction form's values. The payload is not validated with
 * TransactionData's rules: those run when the template is used.
 */
class TransactionTemplateData extends Data
{
    /**
     * Never a server-owned field (FormContractTest's SERVER_ONLY). No `date`: the form
     * seeds today's, and a template saved in March must not restore March.
     */
    public const KEEPS = ['type', 'description', 'amount', 'ccy', 'status'];

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
            // No `unique`: the controller numbers a repeated name instead.
            'name' => ['required', 'string', 'max:255'],

            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],

            'payload' => ['required', 'array'],

            // Rule::enum because nothing derives membership for an array payload.
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

    /** Nulls are kept: derivedAmount() reads an absent unit_price as '0'. */
    public function keptPayload(): array
    {
        $bag = Arr::only($this->payload['meta_data'] ?? [], self::KEEPS_IN_BAG);

        return array_merge(
            Arr::only($this->payload, self::KEEPS),
            $bag === [] ? [] : ['meta_data' => $bag]
        );
    }
}
