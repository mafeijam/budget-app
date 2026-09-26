<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;
    use HasMeta;

    /**
     * The attributes a client may set: the table's columns less `id` and the two
     * timestamps, which are the database's to assign.
     *
     * `id` is here by omission rather than by accident. AccountData carries one,
     * because the edit form round-trips the whole table row, and the controller
     * hands that DTO straight to create() and update() -- so the id in the payload
     * is a number the client chose. Listing the rest and not this is what stops a
     * row being renumbered onto a free id, which would move it with nothing
     * recording that it had. Timestamps are set by Eloquent on save, which assigns
     * them through setAttribute rather than through fill(), so leaving them out
     * costs nothing and keeps a client from backdating created_at.
     *
     * MassAssignmentTest asserts this list against the accounts table, so a column
     * added to the migration and forgotten here fails rather than silently
     * ceasing to be written.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'status',
        'type',
        'ccy',
    ];

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
