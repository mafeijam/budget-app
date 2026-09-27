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
            // the membership check. The `size:3` it replaces was never one -- it
            // accepted 'ZZZ' and 'hkd' as readily as 'HKD'. The settlement field is
            // type-specific and lives in AccountMetaData.
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
     * A constructor check rather than a rule, because only the database knows what
     * the target *is* -- so it holds whether or not the caller called validate().
     * A ValidationException, so it reaches the form as a field error, not a 500.
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

        // The subject and verb for these sentences, spelled out because a brokerage
        // settles into a bank while a card is paid from one. The verb is stored bare
        // because each message puts it in a different grammatical slot.
        [$subject, $verb] = match ($this->type) {
            AccountType::Security => ['brokerage', 'settle into'],
            AccountType::Card => ['card', 'be paid from'],
            // Unreachable -- a cash account is prohibited the field -- but named
            // rather than defaulted, so a new case fails loudly.
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

        // Checked after the type, since a wrong-type target is the more fundamental
        // mismatch and naming its currency would imply converting could fix it.
        //
        // Refuse rather than convert: a *charge* in another currency is fine because
        // the user states it in the card's own currency (card_amount, which
        // CardStatement sums). A *bank* in another currency has no such figure -- the
        // card's statement total is not that amount and nothing here converts between
        // them -- so the pairing is left unusable rather than quietly miscounted.
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
