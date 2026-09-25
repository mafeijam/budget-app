<?php

namespace App\DTO;

use Carbon\Carbon;
use Spatie\LaravelData\Data;

class TransactionData extends Data
{
    public function __construct(
        public ?int $id,
        public int $account_id,
        public int $category_id,
        public string $date,
        public string $type,
        public string $description,
        public string $amount,
        public string $ccy,
        public ?Carbon $created_at,
        // public ?AccountData $account,
        // public ?CategoryData $category
    ) {
        $this->created_at ??= now();
    }

    /**
     * Only the constraints the property types cannot express are declared
     * here. spatie/laravel-data already derives `required` and the type checks
     * from the constructor signature, the same way AccountData does it.
     */
    public static function rules()
    {
        return [
            'account_id' => ['exists:accounts,id'],
            'category_id' => ['exists:categories,id'],

            // The column is a `date`, so only the ISO calendar date is
            // meaningful. No time component, no locale formats.
            'date' => ['date_format:Y-m-d'],

            // The column is decimal(12,4). Note that Laravel's `decimal` rule
            // counts *decimal places*, not integer digits, so `decimal:0,4`
            // caps the scale at four; `max` then caps the magnitude using the
            // eight digits the precision leaves for the integer part.
            //
            // Amount is deliberately a string. It is money, and a float would
            // introduce binary rounding errors. Do not "fix" it to a numeric
            // type; widen the rules instead.
            'amount' => ['decimal:0,4', 'max:99999999.9999'],

            'type' => ['max:255'],
            'description' => ['max:255'],
            'ccy' => ['size:3'],
        ];
    }
}
