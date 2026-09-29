<?php

namespace App\DTO;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

class AccountData extends Data
{
    public function __construct(
        public ?int $id,
        public string $name,
        public AccountStatus $status,
        public AccountType $type,
        public Currency $ccy,
        public ?Carbon $created_at,
        public ?AccountMetaData $meta_data,
    ) {
        $this->created_at ??= now();

        $this->guardSettlementAccount();
    }

    public static function rules(Request $r)
    {
        $unique = Rule::unique('accounts')->ignore($r->route('account'));

        return [
            'name' => ['required', 'string', $unique],

            // No rule for ccy: typing it Currency derives the membership check.
        ];
    }

    public static function attributes()
    {
        return [
            // Otherwise a rejected currency reads "The selected ccy is invalid".
            'ccy' => 'currency',
        ];
    }

    /** In the constructor, so it holds whether or not the caller called validate(). */
    private function guardSettlementAccount(): void
    {
        $id = $this->meta_data?->settlement_account_id;

        if ($id === null) {
            return;
        }

        $target = Account::find($id);

        // No account to inspect: `exists:accounts,id` reports that better.
        if ($target === null) {
            return;
        }

        try {
            Account::guardSettledFrom(
                $target,
                $this->type->value,
                $this->ccy->value,
                Account::settlementWording($this->type->value)
            );
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'meta_data.settlement_account_id' => $e->errors()['settlement_account_id'][0],
            ]);
        }
    }
}
