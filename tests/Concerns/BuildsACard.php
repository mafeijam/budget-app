<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An HKD card closing on the 25th with fifteen days to pay, settled from an HKD bank,
 * and the rows a statement is made of.
 *
 * On those terms a charge on 1 January is billed by the statement closing on 25
 * January and falls due on 9 February, which is PERIOD.
 */
trait BuildsACard
{
    protected const PERIOD = '2026-02-09';

    protected Account $bank;

    protected Account $card;

    protected int $category;

    protected function setUpCard(): void
    {
        $this->bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->card = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $this->card->meta()->create([
            'meta' => ['term_days' => 15, 'statement_day' => 25, 'settlement_account_id' => $this->bank->id],
        ]);
        $this->category = DB::table('categories')->insertGetId(['name' => 'FOOD']);
    }

    /**
     * A charge written straight to the table, filed under the period its date falls in.
     *
     * Straight rather than through store(), so a test about statements does not also
     * depend on the controller's guards. The period is derived from the card's own terms
     * rather than hardcoded, so a charge after the closing day lands a period later.
     */
    protected function charge(string $date, string $amount, string $status = 'posted', array $meta = []): Transaction
    {
        return $this->chargeOn($this->card, $date, $amount, $status, $meta);
    }

    protected function chargeOn(
        Account $card,
        string $date,
        string $amount,
        string $status = 'posted',
        array $meta = []
    ): Transaction {
        $transaction = Transaction::create([
            'account_id' => $card->id,
            'category_id' => $this->category,
            'date' => $date,
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => $amount,
            'ccy' => 'HKD',
            'status' => $status,
        ]);

        $cycle = CardStatementCycle::fromMeta($card->meta?->meta);

        $transaction->meta()->create([
            'meta' => ['due_date' => $cycle?->dueDateFor(Carbon::parse($date))->toDateString()] + $meta,
        ]);

        return $transaction;
    }

    /** A payment on the card against the statement due $dueDate, written straight. */
    protected function payment(string $date, string $amount, string $dueDate): Transaction
    {
        $payment = Transaction::create([
            'account_id' => $this->card->id,
            'category_id' => null,
            'date' => $date,
            'type' => 'payment',
            'description' => 'Payment',
            'amount' => $amount,
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $payment->meta()->create(['meta' => ['due_date' => $dueDate]]);

        return $payment;
    }

    /** What the transaction form posts for a charge on this card. */
    protected function chargePayload(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => [],
        ], $overrides);
    }

    /** Pay off one period, the way the panel's settle button does. */
    protected function settle(array $payload)
    {
        return $this->post("/accounts/{$this->card->id}/settle", $payload);
    }
}
