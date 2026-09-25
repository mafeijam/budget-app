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
}
