<?php

namespace App\Models;

use App\Enums\Frequency;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringTransaction extends Model
{
    /**
     * Not last_recorded_on, which only RecurringPayments may move: a client naming it
     * could skip a payment or have one written twice.
     */
    protected $fillable = [
        'account_id',
        'category_id',
        'type',
        'description',
        'amount',
        'ccy',
        'card_amount',
        'frequency',
        'start_date',
        'end_date',
        'active',
    ];

    protected $casts = ['active' => 'boolean'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The occurrences not yet written, up to and including $through.
     *
     * @return list<string>
     */
    public function dueThrough(Carbon $through): array
    {
        $due = [];

        foreach ($this->occurrences() as $date) {
            if ($date > $through->toDateString()) {
                break;
            }

            $due[] = $date;
        }

        return $due;
    }

    /** Null once the end date has passed. */
    public function nextDate(): ?string
    {
        foreach ($this->occurrences() as $date) {
            return $date;
        }

        return null;
    }

    /**
     * Every occurrence after the last one written, as Y-m-d, ending at the end date.
     * Unbounded without one, so a caller must stop it.
     *
     * @return \Generator<int, string>
     */
    private function occurrences(): \Generator
    {
        $frequency = Frequency::from($this->frequency);
        $first = Carbon::parse($this->start_date)->startOfDay();

        for ($n = 0; ; $n++) {
            $date = $frequency->occurrence($first, $n)->toDateString();

            if ($this->end_date !== null && $date > $this->end_date) {
                return;
            }

            if ($this->last_recorded_on !== null && $date <= $this->last_recorded_on) {
                continue;
            }

            yield $date;
        }
    }
}
