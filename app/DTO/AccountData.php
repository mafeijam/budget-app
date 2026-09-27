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

            // No rule for ccy: typing it Currency makes spatie/laravel-data derive the
            // membership check, and `size:3` never was one -- it accepted 'ZZZ' and
            // 'hkd' as readily as 'HKD'. The settlement field is type-specific and
            // lives in AccountMetaData.
        ];
    }

    public static function attributes()
    {
        return [
            // Otherwise a rejected currency reads "The selected ccy is invalid".
            'ccy' => 'currency',
        ];
    }

    /**
     * Reject a settlement target that is not a cash account in the same currency.
     *
     * A constructor check rather than a rule, because only the database knows what the
     * target *is* -- so it holds whether or not the caller called validate(). The rule
     * itself is Account::guardSettledFrom(), shared with the settle endpoint; the
     * refusal is re-keyed here because the field this form submits is a nested one.
     */
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
