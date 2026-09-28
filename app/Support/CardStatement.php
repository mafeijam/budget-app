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
     * Where a period's key lives inside a bag, quoted for interpolation into SQL.
     *
     * The one place it is written down. forAccount() groups on it and moveDueDate()
     * selects the rows to move by it, and the two have to agree exactly: a pair that
     * had drifted would group a charge under one period and then move it under
     * another, which changes what the card is owed with nothing reporting a change.
     *
     * Bound rather than interpolated would be no use here -- a JSON path is a literal
     * to MySQL, not a value it will take a placeholder for -- and it is a constant
     * either way, so there is nothing here for a request to inject.
     */
    private const DUE_DATE_PATH = "'$.due_date'";

    /**
     * The SQL figure a charge contributes to its card's own total: the stated
     * card-currency amount where it has one, the row's amount otherwise.
     *
     * Shared with App\Support\AccountBalance rather than written out twice. A second
     * copy of this is a second statement of what a charge is worth to its card, and
     * the two would drift -- a card table totalling one way and a statement panel
     * the other, with nothing comparing them.
     *
     * The fallback is only ever reached for a charge already denominated in the card's
     * currency. TransactionData::guardCardAmount() refuses a cross-currency charge
     * with no figure, so by the time a row exists the two branches cannot be confused
     * -- and that is what makes the fallback safe rather than the silent arithmetic
     * error it would otherwise be, adding USD 100 to an HKD total.
     *
     * JSON_EXTRACT rather than JSON_UNQUOTE, so a present-and-null key is not read as
     * the string "null" and cast to zero.
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

        $inCardCurrency = self::cardCurrencySql();

        // Into a local, the way the two above are, rather than concatenated into the
        // SQL: the query is a single quoted string that Pint would otherwise break into
        // three parts and re-quote each on its own.
        $path = self::DUE_DATE_PATH;

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
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(m.meta, {$path}))                   AS due_date,
                    MIN(CASE WHEN t.type = 'charge' THEN t.date END)                 AS first_charge_date,
                    MAX(CASE WHEN t.type = 'charge' THEN t.date END)                 AS last_charge_date,
                    COUNT(CASE WHEN t.type = 'charge'  AND t.status IN ('{$counting}') THEN 1 END) AS charge_count,
                    COUNT(CASE WHEN t.type = 'payment' AND t.status IN ('{$counting}') THEN 1 END) AS payment_count,
                    COUNT(CASE WHEN t.status = 'pending' THEN 1 END)                   AS pending_count,
                    COALESCE(SUM(CASE WHEN t.type = 'charge'  AND t.status IN ('{$counting}') THEN {$inCardCurrency} END), 0) AS charged,
                    COALESCE(SUM(CASE WHEN t.type = 'payment' AND t.status IN ('{$counting}') THEN t.amount END), 0) AS paid
               FROM transactions t
               JOIN meta m ON m.model_id = t.id AND m.model_type = ?
              WHERE t.account_id = ?
                AND JSON_EXTRACT(m.meta, {$path}) IS NOT NULL
              GROUP BY due_date
              ORDER BY due_date",
            // Transaction::class, not Account::class: the bag being read is the
            // transaction's, and the morph is what tells the two apart. Account::class
            // joins nothing and yields an empty result rather than an error, so this
            // is worth stating rather than leaving to the reader.
            [Transaction::class, $card->id]
        );

        // The charge dates are MIN/MAX over CHARGE rows only, so a payment named
        // against the period cannot widen the span it covers -- a payment is not
        // something the statement is for. And they are not filtered by status, unlike
        // every figure beside them: a pending charge is in this period, it simply is
        // not billed yet, which is what pending_count and its badge are for. A period
        // with no charge at all comes back null rather than as the payment's date, and
        // the caller shows nothing for it, because it covers nothing.
        return collect($rows)->map(fn (object $row) => new self(
            dueDate: (string) $row->due_date,
            firstChargeDate: $row->first_charge_date,
            lastChargeDate: $row->last_charge_date,
            chargeCount: (int) $row->charge_count,
            paymentCount: (int) $row->payment_count,
            pendingCount: (int) $row->pending_count,
            charged: self::decimal($row->charged),
            paid: self::decimal($row->paid),
        ));
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

    /**
     * The period as the pages show it: the transactions panel and the home page both.
     *
     * One shape for both, so the two cannot disagree about what a period owes or how
     * long it has left. days_until_due is counted from today(), which is
     * Asia/Hong_Kong, and not in the browser, whose date differs from it for six hours
     * a day -- a statement due today would read as due tomorrow or overdue.
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
     * Every transaction in one period of one card, bags loaded.
     *
     * A period is a shared key rather than a row, so moving it means rewriting that key
     * on every row that carries it. The rows are read here, beside the query that
     * defines the key, because the alternative is the controller restating
     * JSON_EXTRACT on the bag -- a second statement of where a period lives, which
     * would move some rows and not others the moment the two drifted, and a charge
     * left behind is a charge quietly re-billed.
     *
     * A subquery on the bag rather than a join, so the transactions come back as
     * models with their relations intact: the write below goes through meta() and needs
     * nothing from the join that forAccount() wants. A card with no such period yields
     * nothing rather than throwing, like forAccount() with no transactions at all.
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
                // JSON_UNQUOTE on both sides of the comparison, because the extracted
                // value is a JSON string and an unquoted one would never equal a date.
                ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(meta, '.self::DUE_DATE_PATH.')) = ?', [$dueDate])
            )
            ->get();
    }

    /**
     * Move one period of a card to a different day it falls due.
     *
     * The due date a period carries is a prediction, made from the card's statement day
     * and term. The bank states a day of its own when it issues the statement, and the
     * two are not always the same -- so this replaces the prediction with the fact, for
     * one period, and leaves the card's terms alone. What it does not do is correct
     * anything entered later: a charge recorded tomorrow is placed by the terms as they
     * stand, so a card whose terms are simply wrong needs those changed, on the account.
     *
     * A period and not a charge, deliberately. Re-dating one charge moves it out of the
     * bill it was counted on and the statement's total no longer adds up to the charges
     * it covers; the whole set has to move together, which is what this does.
     *
     * Every refusal is a ValidationException on `due_date` rather than a return or a
     * thrown exception, so it reaches the dialog as a field error beside the statement
     * it is about rather than as a failed save.
     *
     * @param  string  $from  the day the period is currently keyed on
     * @param  string  $to  the day the bank stated it falls due
     */
    public static function moveDueDate(Account $card, string $from, string $to): void
    {
        // Nothing to do, and not an error: a caller that always sends the period's own
        // date should not have to know whether it changed. Re-running the write would
        // touch every bag in the period to set the key each already carries.
        if ($from === $to) {
            return;
        }

        $refuse = fn (string $message) => throw ValidationException::withMessages(['due_date' => $message]);

        $periods = self::forAccount($card);

        $statement = $periods->firstWhere('dueDate', $from);

        if ($statement === null) {
            $refuse(sprintf('Card [%s] has no statement due %s.', $card->name, $from));
        }

        // A settled period is the record of a bill that has been paid, and there is no
        // un-settling: renaming it would leave a payment explaining less than the money
        // that left the bank. The panel does not offer this for one, so the only way here
        // is a stale page or a hand-made request -- which is exactly what a guard is for.
        if ($statement->isSettled()) {
            $refuse(sprintf(
                'The statement due %s has been settled, so its due date cannot be changed. '
                    .'It is the record of a bill that has been paid.',
                $from
            ));
        }

        // Not settled, and not issued either: a pending row shares the key, so moving
        // the period would move a charge the bank has not billed onto a statement that
        // says nothing about it. Leaving it behind is the other half of that, and there
        // is no way to move one row out of a period today.
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

        // The one that would be silent. Two periods under one key are one row out of
        // forAccount(), so the two bills would arrive already merged -- the panel would
        // show a single period owing both, and settling it would write one payment
        // against money owed for two.
        if ($periods->contains(fn (self $period) => $period->dueDate === $to)) {
            $refuse(sprintf(
                'Card [%s] already has a statement due %s. Two statements cannot fall due on '
                    .'the same day, and moving this one there would merge the two bills.',
                $card->name,
                $to
            ));
        }

        // Lexicographic because both sides are Y-m-d. Always true of a derived date --
        // the term is at least a day, counted from a closing day on or after the charge
        // -- so a violation is a mistyped date rather than an unusual card.
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
                // Merged rather than replaced, and that is the whole of it: the bag
                // carries card_amount -- the figure a cross-currency charge is worth to
                // the card, which cardCurrencySql() prefers over the row's own amount --
                // and paired_transaction_id, which is the whole of what makes deleting a
                // settlement delete both halves. Writing just the key would drop both,
                // and quietly change what the card is owed.
                $row->meta()->update([
                    'meta' => array_merge(
                        $row->meta->meta->getArrayCopy(),
                        ['due_date' => $to]
                    ),
                ]);
            }
        });
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
