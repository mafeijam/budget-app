<?php

namespace App\DTO;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        public ?AccountMetaData $meta_data
    ) {
        $this->created_at ??= now();
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
        ];
    }
}
