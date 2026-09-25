<?php

namespace App\DTO;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class AccountData extends Data
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $status,
        public string $type,
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
        ];
    }
}
