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

            // No rule for ccy: typing it Currency makes spatie/laravel-data derive
            // the membership check. The `size:3` this replaces was never one -- it
            // accepted 'ZZZ' and 'hkd' as readily as 'HKD'. The column is
            // varchar(255) and every case is three characters, so nothing is lost.
            //
            // No settlement rule here: that field is type-specific and lives in
            // AccountMetaData with the card terms. See the comment there on why a
            // nested rule can still read `type` and `id` from the root.
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
        $id = $this->meta_data?->settlement_account_id;

        if ($id === null) {
            return;
        }

        $target = Account::find($id);

        // No account to inspect: `exists:accounts,id` reports that, and throwing
        // here as well would mask it with a less accurate message.
        if ($target === null) {
            return;
        }

        // How this account type is named, and the verb that goes with it. Written out
        // rather than read from the enum because the verbs genuinely differ -- a
        // brokerage settles into a bank, a card is paid from one -- and a shared
        // "account" would be vaguer than either situation warrants. Kept beside the
        // messages rather than on the enum, because these are sentences about this
        // field, not facts about an account type.
        // The verb is stored bare -- "settle into", "be paid from" -- because both
        // messages need it in a different grammatical slot, and a stored phrase with
        // the subject baked in produced "cannot is paid from".
        [$subject, $verb] = match ($this->type) {
            AccountType::Security => ['brokerage', 'settle into'],
            AccountType::Card => ['card', 'be paid from'],
            // Unreachable: a cash account is prohibited the field outright. Named
            // rather than defaulted to, so a case added to the enum without a decision
            // here fails loudly rather than producing a nonsense sentence.
            AccountType::Cash => ['cash account', 'settle into'],
        };

        if ($target->type !== AccountType::Cash->value) {
            throw ValidationException::withMessages([
                'meta_data.settlement_account_id' => sprintf(
                    'A %s can only %s a cash account, not a %s account.',
                    $subject,
                    $verb,
                    $target->type
                ),
            ]);
        }

        // Checked after the type, because a wrong-type target is the more
        // fundamental mismatch: naming its currency would imply the pairing could
        // be fixed by converting, and it cannot.
        //
        // Refuse rather than convert. A transaction's fx_rate exists and is wired to
        // nothing, so there is no rate to convert at, and the proceeds would need
        // converting back again. The pairing is real and cannot be recorded until
        // that lands; today the account is unusable rather than silently
        // miscounted.
        //
        // Both currencies named, because there are two accounts to change.
        if ($target->ccy !== $this->ccy->value) {
            throw ValidationException::withMessages([
                'meta_data.settlement_account_id' => sprintf(
                    'A %s %s cannot %s a %s account.',
                    $this->ccy->value,
                    $subject,
                    $verb,
                    $target->ccy
                ),
            ]);
        }
    }
}
