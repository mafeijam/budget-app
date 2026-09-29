<?php

namespace App\Support;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One statement period of a card: not a row but a due_date in the meta bag, shared by
 * every charge and payment that fell into it.
 *
 * The grouping scans all of `meta` once per card, since MySQL cannot index a JSON path;
 * the measured remedy is in create_transactions_table.
 */
class CardStatement
{
    /**
     * One copy for forAccount() and rowsInPeriod(): two that drifted would group a charge
     * under one period and move it under another. Interpolated, as MySQL takes no
     * placeholder for a JSON path.
     */
    private const DUE_DATE_PATH = "'$.due_date'";

    /**
     * What a charge is worth to its card, shared with AccountBalance. The fallback is safe
     * only because guardCardAmount() refuses a cross-currency charge with no card_amount.
     *
     * JSON_EXTRACT rather than JSON_UNQUOTE, or a present-and-null key reads as "null"
     * and casts to zero.
     *
     * @param  string  $fallback  the expression for the row's own amount
     */
    public static function cardCurrencySql(string $fallback = 't.amount'): string
    {
        return 'COALESCE(CAST(JSON_EXTRACT(m.meta, \'$.card_amount\') AS DECIMAL(12,4)), '.$fallback.')';
    }

    /**
     * @param  string  $dueDate  the day this period is payable
     * @param  string|null  $firstChargeDate  the earliest charge date in the period
     * @param  string|null  $lastChargeDate  the latest charge date in the period
     * @param  int  $chargeCount  charges counted toward the balance, pending excluded
     * @param  int  $paymentCount  payments counted toward the balance, pending excluded
     * @param  int  $pendingCount  rows in the period that do not count, of either kind
     * @param  string  $charged  total charged in, to four decimal places
     * @param  string  $paid  total paid against it, to four decimal places
     */
    public function __construct(
        public readonly string $dueDate,
        public readonly ?string $firstChargeDate,
        public readonly ?string $lastChargeDate,
        public readonly int $chargeCount,
        public readonly int $paymentCount,
        public readonly int $pendingCount,
        public readonly string $charged,
        public readonly string $paid,
    ) {}

    /**
     * Earliest first.
     *
     * @return Collection<int, self>
     */
    public static function forAccount(Account $card): Collection
    {
        return self::forAccounts(collect([$card]))[$card->id] ?? collect();
    }

    /**
     * Every card's periods, in one query, keyed by card id.
     *
     * The grouping scans the meta bag rather than using an index -- MySQL cannot index a
     * JSON path -- so it is a full pass over the table, and a page wanting seven cards
     * was paying for it seven times over. Home reads them and so does the forecast, which
     * is fourteen passes to answer one question twice.
     *
     * @param  Collection<int, Account>  $cards
     * @return array<int, Collection<int, self>> card id => its periods, earliest first
     */
    public static function forAccounts(Collection $cards): array
    {
        if ($cards->isEmpty()) {
            return [];
        }

        // Interpolated from the enum: MySQL takes no placeholder inside a CASE.
        $counting = implode("', '", TransactionStatus::countingTowardBalance());

        $inCardCurrency = self::cardCurrencySql();

        $path = self::DUE_DATE_PATH;

        $placeholders = implode(', ', array_fill(0, $cards->count(), '?'));

        $rows = DB::select(
            // Status inside each CASE, not in the WHERE: a hidden pending row cannot be
            // counted, and the period would be offered for settlement then reopen when
            // the charge posted.
            //
            // JSON_EXTRACT in the filter, so a present-and-null key drops out rather
            // than becoming a period with no date.
            "SELECT t.account_id,
                    JSON_UNQUOTE(JSON_EXTRACT(m.meta, {$path}))                   AS due_date,
                    MIN(CASE WHEN t.type = 'charge' THEN t.date END)                 AS first_charge_date,
                    MAX(CASE WHEN t.type = 'charge' THEN t.date END)                 AS last_charge_date,
                    COUNT(CASE WHEN t.type = 'charge'  AND t.status IN ('{$counting}') THEN 1 END) AS charge_count,
                    COUNT(CASE WHEN t.type = 'payment' AND t.status IN ('{$counting}') THEN 1 END) AS payment_count,
                    COUNT(CASE WHEN t.status = 'pending' THEN 1 END)                   AS pending_count,
                    COALESCE(SUM(CASE WHEN t.type = 'charge'  AND t.status IN ('{$counting}') THEN {$inCardCurrency} END), 0) AS charged,
                    COALESCE(SUM(CASE WHEN t.type = 'payment' AND t.status IN ('{$counting}') THEN t.amount END), 0) AS paid
               FROM transactions t
               JOIN meta m ON m.model_id = t.id AND m.model_type = ?
              WHERE t.account_id IN ({$placeholders})
                AND JSON_EXTRACT(m.meta, {$path}) IS NOT NULL
           GROUP BY t.account_id, due_date
           ORDER BY due_date",
            // Transaction::class: Account::class joins nothing and returns empty, not an error.
            [Transaction::class, ...$cards->pluck('id')->all()]
        );

        // A plain array, not a Collection: these are appended to by key as the rows come
        // back, and a Collection silently drops that.
        $series = [];

        foreach ($cards as $card) {
            $series[$card->id] = [];
        }

        // The charge dates ignore payments, which cannot widen the span, and ignore
        // status, since a pending charge is still in the period.
        foreach ($rows as $row) {
            $series[(int) $row->account_id][] = new self(
                dueDate: (string) $row->due_date,
                firstChargeDate: $row->first_charge_date,
                lastChargeDate: $row->last_charge_date,
                chargeCount: (int) $row->charge_count,
                paymentCount: (int) $row->payment_count,
                pendingCount: (int) $row->pending_count,
                charged: self::decimal($row->charged),
                paid: self::decimal($row->paid),
            );
        }

        return array_map(fn (array $periods) => collect($periods), $series);
    }

    /** Signed: clamping an overpayment at zero would report it as a clean settlement. */
    public function owed(): string
    {
        return self::decimal(BigDecimal::of($this->charged)->minus($this->paid));
    }

    /**
     * days_until_due from today(), Hong Kong's day: the browser's differs six hours a day.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'first_charge_date' => $this->firstChargeDate,
            'last_charge_date' => $this->lastChargeDate,
            'due_date' => $this->dueDate,
            'charge_count' => $this->chargeCount,
            'payment_count' => $this->paymentCount,
            'pending_count' => $this->pendingCount,
            'charged' => $this->charged,
            'paid' => $this->paid,
            'owed' => $this->owed(),
            'days_until_due' => (int) today()->diffInDays(Carbon::parse($this->dueDate), false),
        ];
    }

    public function isSettled(): bool
    {
        return BigDecimal::of($this->owed())->isEqualTo(BigDecimal::zero());
    }

    /** Why a period cannot be settled: it would reopen once the pending charge posted. */
    public function hasPendingActivity(): bool
    {
        return $this->pendingCount > 0;
    }

    /**
     * Here, beside forAccount(), so where a period lives is written once. A subquery
     * rather than a join, so the rows come back as models.
     *
     * @return Collection<int, Transaction>
     */
    public static function rowsInPeriod(Account $card, string $dueDate): Collection
    {
        return Transaction::query()
            ->with('meta')
            ->where('account_id', $card->id)
            ->whereIn('id', DB::table('meta')
                ->select('model_id')
                ->where('model_type', Transaction::class)
                // Unquoted, or the JSON string would never equal a date.
                ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(meta, '.self::DUE_DATE_PATH.')) = ?', [$dueDate])
            )
            ->get();
    }

    /**
     * Replaces the terms' predicted due date with the one the bank stated, for one period
     * and every row in it. The card's terms are left alone, so later charges are placed
     * by them as before.
     *
     * @param  string  $from  the day the period is currently keyed on
     * @param  string  $to  the day the bank stated it falls due
     */
    public static function moveDueDate(Account $card, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $refuse = fn (string $message) => throw ValidationException::withMessages(['due_date' => $message]);

        $periods = self::forAccount($card);

        $statement = $periods->firstWhere('dueDate', $from);

        if ($statement === null) {
            $refuse(sprintf('Card [%s] has no statement due %s.', $card->name, $from));
        }

        // The panel does not offer this, so only a stale page or a hand-made request gets here.
        if ($statement->isSettled()) {
            $refuse(sprintf(
                'The statement due %s has been settled, so its due date cannot be changed. '
                    .'It is the record of a bill that has been paid.',
                $from
            ));
        }

        // A pending row shares the key, so it would move onto a statement that does not bill it.
        if ($statement->hasPendingActivity()) {
            $refuse(sprintf(
                'The statement due %s has %d row%s not yet posted, so it has not been issued '
                    .'and its due date is not final. Post or remove %s first.',
                $from,
                $statement->pendingCount,
                $statement->pendingCount === 1 ? '' : 's',
                $statement->pendingCount === 1 ? 'it' : 'them'
            ));
        }

        // The silent one: two periods under one key come out of forAccount() as one bill.
        if ($periods->contains(fn (self $period) => $period->dueDate === $to)) {
            $refuse(sprintf(
                'Card [%s] already has a statement due %s. Two statements cannot fall due on '
                    .'the same day, and moving this one there would merge the two bills.',
                $card->name,
                $to
            ));
        }

        // Lexicographic on Y-m-d. A derived date always passes, so this catches a typo.
        if ($statement->lastChargeDate !== null && $to <= $statement->lastChargeDate) {
            $refuse(sprintf(
                'The statement due %s covers charges up to %s, so it cannot fall due on %s.',
                $from,
                $statement->lastChargeDate,
                $to
            ));
        }

        DB::transaction(function () use ($card, $from, $to) {
            foreach (self::rowsInPeriod($card, $from) as $row) {
                // Merged: replacing the bag would drop card_amount and paired_transaction_id,
                // changing what the card owes and unpairing a settlement.
                $row->meta()->update([
                    'meta' => array_merge(
                        $row->meta->meta->getArrayCopy(),
                        ['due_date' => $to]
                    ),
                ]);
            }
        });
    }

    /** The COALESCE's integer 0 would read "0" beside "120.0000". */
    private static function decimal(int|string|BigDecimal $value): string
    {
        $decimal = $value instanceof BigDecimal ? $value : BigDecimal::of((string) $value);

        return $decimal->toScale(4)->toString();
    }
}
