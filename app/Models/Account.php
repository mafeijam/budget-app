<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;
    use HasMeta;

    /**
     * The cash account a securities account settles through.
     *
     * A method rather than a belongsTo, because the link lives in the meta bag
     * and Eloquent cannot join on a JSON path. That is the cost of keeping it
     * there: one query per call, no eager loading, and any "which brokerages
     * settle into this bank" question has to reach into the JSON. What it buys
     * is that a new account type needing a pointer to another account needs no
     * migration. Null for a cash or card account, which AccountMetaData prohibits
     * the field for rather than merely leaving it unset.
     */
    public function settlementAccount(): ?self
    {
        $id = $this->meta?->meta['settlement_account_id'] ?? null;

        return $id === null ? null : self::find($id);
    }
}
