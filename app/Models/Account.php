<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    use HasFactory;
    use HasMeta;

    /**
     * The cash account a securities account settles through.
     *
     * Self-referential, so that reads oddly until you remember both ends of the
     * link are accounts. Null for a cash or card account, which AccountData
     * prohibits the column for rather than merely leaving it unset.
     */
    public function settlementAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settlement_account_id');
    }
}
