<?php

namespace App\DTO;

use Spatie\LaravelData\Data;

class AccountMetaData extends Data
{
    public function __construct(
        public ?string $due
    ) {}

    public static function rules()
    {
        return [
            'due' => ['required_if:type,card',  'max:28'],
        ];
    }

    public static function attributes()
    {
        return [
            'due' => 'due date',
        ];
    }
}
