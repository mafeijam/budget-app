<?php

namespace App\Support;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One statement period of a credit card, and what it still owes.
 *
 * A period is not a row. It is a due_date shared by however many charges and
 * payments fell into it, and what the cardholder owes for that period is the
 * difference. That grouping is the whole reason a card statement is a concept at
 * all: four charges on the 3rd are one bill on the 9th, not four obligations, and
 * paying it is one act.
 *
 * The due_date lives in the meta bag, so this reads it with JSON_EXTRACT and groups
 * in SQL over one account's transactions. That query used to be served by
 * transactions_account_due_index, which was dropped when due_date moved off the
 * column -- MySQL cannot index a JSON path.
 *
 * The cost of that is measured rather than predicted, and it is worse than
 * "scans the account's transactions". On 180 transactions the optimizer drives from
 * the meta table and scans all of it -- type ALL, key NULL -- once per card, since
 * the JSON path is unindexable and every bag in the database looks like a candidate.
 * The measured remedy, including the version that MySQL refuses outright, is in
 * create_transactions_table. Not applied here: whether it is worth an index depends
 * on how much history this app is expected to hold, which is not known yet.
 *
 * Money is never a float. The totals come out of MySQL as exact decimals and stay
 * strings, and the comparisons use BigDecimal, because 0.1 + 0.2 drifting to
 * 0.30000000000000004 is not a thing that should reach a balance a user reads.
 */
class CardStatement
{
    /**
     * @param  string  $dueDate  the day this period is payable
     * @param  int  $chargeCount  charges counted toward the balance, pending excluded
     * @param  int  $paymentCount  payments counted toward the balance, pending excluded
     * @param  int  $pendingCount  rows in the period that do not count, of either kind
     * @param  string  $charged  total charged in, to four decimal places
     * @param  string  $paid  total paid against it, to four decimal places
     */
    public function __construct(
        public readonly string $dueDate,
        public readonly int $chargeCount,
        public readonly int $paymentCount,
        public readonly int $pendingCount,
        public readonly string $charged,
        public readonly string $paid,
    ) {}

    /**
     * Every statement period on a card, earliest first.
     *
     * A card with no transactions yields nothing rather than throwing, so a caller
     * listing a card's statements does not first have to know whether it has any.
     *
     * @return Collection<int, self>
     */
    public static function forAccount(Account $card): Collection
    {
        // Read from the enum rather than written into the SQL, because a hand-written
        // IN list is a second statement of countsTowardBalance() and the two would
        // drift: a case added to the enum would be left out of the list and the
        // balance would quietly exclude a state nobody told it to.
        //
        // Interpolated rather than bound because MySQL will not take a placeholder
        // inside a CASE expression, and the values are enum cases rather than
        // anything a request supplied -- there is nothing here to inject.
        $counting = implode("', '", TransactionStatus::countingTowardBalance());

        // What a charge contributes to its card's statement: its own card-currency
        // amount where it has one, and its amount otherwise.
        //
        // The fallback is only ever reached for a charge already denominated in the
        // card's currency. TransactionData::guardCardAmount() refuses a cross-currency
        // charge with no figure, so by the time a row exists the two branches cannot be
        // confused -- and that is what makes the fallback safe rather than the silent
        // arithmetic error it would otherwise be, adding USD 100 to an HKD total.
        //
        // JSON_EXTRACT rather than JSON_UNQUOTE, as in the join below, so a
        // present-and-null key is not read as the string "null" and cast to zero.
        $inCardCurrency = 'COALESCE('
            ."CAST(JSON_EXTRACT(m.meta, '$.card_amount') AS DECIMAL(12,4)), "
            .'t.amount)';

        $rows = DB::select(
            // The status filter sits inside each CASE rather than in the WHERE,
            // deliberately. In the WHERE it would hide a pending row outright, and a
            // hidden pending row cannot be counted -- so the period would read as
            // final and be offered for settlement, then reopen when the pending
            // charge posted and the user had already paid it. Here a pending row is
            // excluded from the arithmetic and still counted, which is the only way
            // both facts survive the one query.
            //
            // JSON_EXTRACT, not JSON_UNQUOTE, in the join: an absent key is SQL NULL
            // and drops out, while a JSON null is a JSON value and would not. The
            // controller filters nulls out of the bag before storing it, so a row
            // with no period has the key absent rather than present-and-null -- but
            // relying on that would make the answer depend on a decision made three
            // layers up, and this query would then include a "period" with no date.
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(m.meta, '$.due_date'))                  AS due_date,
                    COUNT(CASE WHEN t.type = 'charge'  AND t.status IN ('{$counting}') THEN 1 END) AS charge_count,
                    COUNT(CASE WHEN t.type = 'payment' AND t.status IN ('{$counting}') THEN 1 END) AS payment_count,
                    COUNT(CASE WHEN t.status = 'pending' THEN 1 END)                   AS pending_count,
                    COALESCE(SUM(CASE WHEN t.type = 'charge'  AND t.status IN ('{$counting}') THEN {$inCardCurrency} END), 0) AS charged,
                    COALESCE(SUM(CASE WHEN t.type = 'payment' AND t.status IN ('{$counting}') THEN t.amount END), 0) AS paid
               FROM transactions t
               JOIN meta m ON m.model_id = t.id AND m.model_type = ?
              WHERE t.account_id = ?
                AND JSON_EXTRACT(m.meta, '$.due_date') IS NOT NULL
              GROUP BY due_date
              ORDER BY due_date",
            // Transaction::class, not Account::class: the bag being read is the
            // transaction's, and the morph is what tells the two apart. Account::class
            // joins nothing and yields an empty result rather than an error, so this
            // is worth stating rather than leaving to the reader.
            [Transaction::class, $card->id]
        );

        return collect($rows)->map(fn (object $row) => new self(
            dueDate: (string) $row->due_date,
            chargeCount: (int) $row->charge_count,
            paymentCount: (int) $row->payment_count,
            pendingCount: (int) $row->pending_count,
            charged: self::decimal($row->charged),
            paid: self::decimal($row->paid),
        ));
    }

    /**
     * The periods on a card that still owe something, earliest first.
     *
     * What a panel listing them wants, and bounded without a limit clause: a period
     * that has been paid off is a fact about the past, and a card with five years of
     * settled history would otherwise push the periods needing attention off the
     * bottom of the page. Settled periods are still in the transaction list, where
     * the payment that closed them is.
     *
     * @return Collection<int, self>
     */
    public static function outstandingFor(Account $card): Collection
    {
        return self::forAccount($card)->reject->isSettled()->values();
    }

    /**
     * What this period still owes.
     *
     * Signed, so an overpayment reads negative rather than clamping at zero. One
     * payment covering two periods, or a credit left on the card, is a real state,
     * and clamping would report it as a clean settlement of a period that is not
     * what the user thinks it is.
     */
    public function owed(): string
    {
        return self::decimal(BigDecimal::of($this->charged)->minus($this->paid));
    }

    public function isSettled(): bool
    {
        return BigDecimal::of($this->owed())->isEqualTo(BigDecimal::zero());
    }

    /**
     * Whether this period holds anything not yet billed or confirmed.
     *
     * The reason a period cannot be settled. The owed figure is correct *without*
     * the pending rows -- that is what pending means -- so a user who trusted the
     * number would pay against a statement that grows once the pending charge
     * posts, and the period would reopen under them having already settled it.
     */
    public function hasPendingActivity(): bool
    {
        return $this->pendingCount > 0;
    }

    /**
     * A decimal at exactly the column's scale, as a plain string.
     *
     * MySQL returns SUM() over decimal(12,4) as a decimal already, but a period
     * with no rows of one kind comes back as the COALESCE's integer 0 and would
     * otherwise read "0" beside "120.0000". Normalising here means every figure has
     * the same shape, which is what the table and the settle button assume.
     */
    private static function decimal(int|string|BigDecimal $value): string
    {
        $decimal = $value instanceof BigDecimal ? $value : BigDecimal::of((string) $value);

        return $decimal->toScale(4)->toString();
    }
}
