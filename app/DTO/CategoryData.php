<?php

namespace App\DTO;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class CategoryData extends Data
{
    public function __construct(
        public ?int $id,
        public string $name,
        public ?Carbon $created_at,
    ) {
        $this->created_at ??= now();
    }

    public static function rules(Request $r)
    {
        $unique = Rule::unique('categories')->ignore($r->route('category'));

        return [
            'name' => ['required', 'string', $unique],
        ];
    }
}
