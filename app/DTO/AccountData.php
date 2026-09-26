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

        // Required for a securities account, prohibited for every other type, and
        // the target must be a cash account -- see guardSettlementAccount().
        public ?int $settlement_account_id,
    ) {
        $this->created_at ??= now();

        $this->guardSettlementAccount();
    }

    public static function rules(Request $r)
    {
        $unique = Rule::unique('accounts')->ignore($r->route('account'));

        return [
            'name' => ['required', 'string', $unique],

            // No rule for ccy: typing it Currency makes spatie/laravel-data derive
            // the membership check. The `size:3` this replaces was never one -- it
            // accepted 'ZZZ' and 'hkd' as readily as 'HKD'. The column is
            // varchar(255) and every case is three characters, so nothing is lost.
            //
            // `nullable` first on settlement_account_id because the validator counts
            // null as present, so `integer` and `exists` would otherwise fire on
            // every cash and card account whose form was never touched.
            // required_if and prohibited_unless are implicit and survive.
            //
            // Between them the two say the column is present exactly when the
            // account is a securities one, and the prohibition is what makes a
            // settlement cycle unrepresentable: a cycle needs a cash account in the
            // middle, and a cash account may not carry the field.
            //
            // `different` is redundant with guardSettlementAccount() -- a securities
            // account is not cash, so it cannot point at itself. It is here for the
            // message, which would otherwise blame the target.
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
            // Without this a rejected currency reads "The selected ccy is invalid",
            // the one field error a user cannot act on. The form's label reads
            // "Currency" to match.
            'ccy' => 'currency',
        ];
    }

    /**
     * Reject a securities account that settles into anything but a cash account in
     * the same currency.
     *
     * A constructor check rather than a rule, because a rule sees only the payload
     * and what the target *is* is knowable only from the database -- so it holds
     * whether or not the caller remembered to call validate(). Thrown as a
     * ValidationException so it reaches the form as a field error, not a 500.
     */
    private function guardSettlementAccount(): void
    {
        if ($this->settlement_account_id === null) {
            return;
        }

        $target = Account::find($this->settlement_account_id);

        // No account to inspect: `exists:accounts,id` reports that, and throwing
        // here as well would mask it with a less accurate message.
        if ($target === null) {
            return;
        }

        if ($target->type !== AccountType::Cash->value) {
            throw ValidationException::withMessages([
                'settlement_account_id' => sprintf(
                    'A securities account settles into a cash account, not a %s account.',
                    $target->type
                ),
            ]);
        }

        // Checked after the type, because a wrong-type target is the more
        // fundamental mismatch: naming its currency would imply the pairing could
        // be fixed by converting, and it cannot.
        //
        // Refuse rather than convert. transactions.fx_rate exists and is wired to
        // nothing, so there is no rate to convert at, and the proceeds would need
        // converting back again. The pairing is real and cannot be recorded until
        // that lands; today the account is unusable rather than silently
        // miscounted.
        //
        // Both currencies named, because there are two accounts to change.
        if ($target->ccy !== $this->ccy->value) {
            throw ValidationException::withMessages([
                'settlement_account_id' => sprintf(
                    'A %s brokerage cannot settle into a %s account.',
                    $this->ccy->value,
                    $target->ccy
                ),
            ]);
        }
    }
}
