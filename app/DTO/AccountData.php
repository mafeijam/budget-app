<?php

namespace App\DTO;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
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
        public string $ccy,
        public ?Carbon $created_at,
        public ?AccountMetaData $meta_data,

        // Where a securities account settles. Required for one, prohibited for
        // every other type, and the target must be a cash account -- see
        // guardSettlementAccount().
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

            // ISO 4217 alphabetic codes. The column is varchar(255), so without
            // a cap a long value reaches MySQL and AccountController turns the
            // failure into a generic "error db" redirect instead of a 422.
            'ccy' => ['string', 'size:3'],

            // `nullable` first because a blank form field and empty() both
            // produce null, and the validator counts null as "present" -- without
            // it `integer` and `exists` would fire on every cash and card account
            // whose form was never touched. required_if and prohibited_unless are
            // implicit and survive, which is what still rejects a brokerage with
            // nowhere to settle.
            //
            // Together those two say the column is present exactly when the
            // account is a securities account. The prohibition is doing more work
            // than it looks: closing the second hop is what makes a settlement
            // cycle unrepresentable, since a cycle needs a cash account in the
            // middle and a cash account may not carry the field.
            //
            // `different` is redundant with guardSettlementAccount() -- a
            // securities account is not cash, so it cannot point at itself. It is
            // here for the message: without it the user is told the target is not
            // a cash account, which is true but does not say they pointed the
            // account at itself.
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

    /**
     * Reject a securities account that settles into anything but a cash account.
     *
     * A rule can only see the payload, and what the target *is* is only knowable
     * from the database, so this runs in the constructor. That means it holds
     * whether or not the caller remembered to call validate().
     *
     * Reported as a ValidationException so it reaches the form as a field error
     * rather than a 500.
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
    }
}
