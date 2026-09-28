<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved set of the transaction form's values.
 *
 * Not a transaction and never counted as one: nothing sums a template, and a position
 * never replays one. What it is for is filling the form in again, so the payload is read
 * whole and merged over the form rather than picked apart a key at a time.
 *
 * The account and the category are columns rather than payload keys, so the rows a
 * cascade removes are rows rather than documents -- see create_transaction_templates_table.
 */
class TransactionTemplate extends Model
{
    /**
     * Every column but the id and the timestamps, which is the whole of what a client
     * may set. MassAssignmentTest derives the same list from the schema, so a column
     * added to the migration and forgotten here fails there rather than silently
     * stopping being written.
     */
    protected $fillable = ['name', 'account_id', 'category_id', 'payload'];

    /**
     * A plain array, where Meta casts its bag to an ArrayObject. That one is read a key
     * at a time from a dozen places; this one is spread over a form's schema and read
     * whole, which is what an array is for.
     */
    protected $casts = ['payload' => 'array'];

    /**
     * The account the template's transactions belong to. The payload cannot say, which
     * is why this is a column: it is also what the picker groups by.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The category its transactions are filed under, or none.
     *
     * A relation rather than nothing to read, so deleting a category is the database's
     * cascade rather than a query this app has to remember to run.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
