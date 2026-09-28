<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\CardStatement;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a card statement period owes, and what it does not count.
 *
 * This is the query the settlement index used to serve. due_date went into the meta
 * bag in 685de17 and transactions_account_due_index went with it, on the reasoning
 * that MySQL cannot index a JSON path and that the price was worth paying because
 * no query grouped by it. This is that query, so the price is no longer
 * hypothetical -- the reasoning is in create_transactions_table, and this file is
 * where it either holds up or does not.
 *
 * The arithmetic is charges minus payments per due_date, over the statuses that
 * count toward a balance. Pending rows are the interesting part: a pending charge
 * is not owed yet, so it must not inflate the figure, but it is also not gone, so a
 * period holding one cannot be settled without the user being told why.
 */
class CardStatementTest extends TestCase
{
    use RefreshDatabase;

    private Account $card;

    private Account $otherCard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->card = $this->card('Primary', ['term_days' => 15, 'statement_day' => 25]);
        $this->otherCard = $this->card('Secondary', ['term_days' => 15, 'statement_day' => 10]);
    }

    // ---------------------------------------------------------------------
    // The shape of the answer
    // ---------------------------------------------------------------------

    public function test_a_card_with_no_transactions_has_no_statements(): void
    {
        $this->assertSame(0, CardStatement::forAccount($this->card)->count());
    }

    public function test_a_charge_becomes_a_period_owing_that_charge(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertSame('2026-02-09', $statement->dueDate);
        $this->assertSame('120.0000', $statement->charged);
        $this->assertSame('0.0000', $statement->paid);
        $this->assertSame('120.0000', $statement->owed());
        $this->assertFalse($statement->isSettled());
    }

    public function test_two_charges_in_one_period_are_summed(): void
    {
        // Both on or before the 25th, so both fall in the statement closing that
        // day and are payable together.
        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-01-20', '80.5000');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertSame(2, $statement->chargeCount);
        $this->assertSame('200.5000', $statement->charged);
        $this->assertSame('200.5000', $statement->owed());
    }

    public function test_charges_in_different_periods_stay_apart(): void
    {
        // The 26th is after the closing day, so it belongs to the next statement.
        $this->charge('2026-01-20', '120.0000');
        $this->charge('2026-01-26', '80.5000');

        $statements = CardStatement::forAccount($this->card);

        $this->assertSame(2, $statements->count());
        $this->assertSame('2026-02-09', $statements->first()->dueDate);
        $this->assertSame('2026-03-12', $statements->last()->dueDate);
        $this->assertSame('120.0000', $statements->first()->owed());
        $this->assertSame('80.5000', $statements->last()->owed());
    }

    public function test_periods_come_back_in_due_date_order(): void
    {
        // Ordered, because the earliest unpaid is the one to settle next and a
        // list that does not say which is first is a list the user has to sort.
        $this->charge('2026-03-01', '10.0000');
        $this->charge('2026-01-01', '20.0000');
        $this->charge('2026-02-01', '30.0000');

        $this->assertSame(
            ['2026-02-09', '2026-03-12', '2026-04-09'],
            CardStatement::forAccount($this->card)->map->dueDate->all()
        );
    }

    public function test_a_payment_reduces_what_the_period_owes(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->payment('2026-02-01', '50.0000', 'posted', '2026-02-09');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertSame('120.0000', $statement->charged);
        $this->assertSame('50.0000', $statement->paid);
        $this->assertSame('70.0000', $statement->owed());
    }

    public function test_a_fully_paid_period_reads_zero_and_is_settled(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->payment('2026-02-01', '120.0000', 'posted', '2026-02-09');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertSame('0.0000', $statement->owed());
        $this->assertTrue($statement->isSettled());
    }

    public function test_an_overpayment_reads_negative_rather_than_clamping_to_zero(): void
    {
        // Clamping would turn a 30 credit into "0 owed, settled", and that is a
        // different fact with the opposite consequence: a period in credit is not a
        // period that has been paid off, it is one holding the user's money. Which is
        // why isSettled() is false here even though the figure has crossed zero --
        // settled means nothing outstanding, not "not positive".
        $this->charge('2026-01-01', '120.0000');
        $this->payment('2026-02-01', '150.0000', 'posted', '2026-02-09');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertSame('-30.0000', $statement->owed());
        $this->assertFalse($statement->isSettled());
    }

    // ---------------------------------------------------------------------
    // Pending rows: excluded from the figure, but still counted
    // ---------------------------------------------------------------------

    public function test_a_pending_charge_is_not_owed_yet(): void
    {
        $this->charge('2026-01-01', '120.0000', 'pending');
        $this->charge('2026-01-02', '80.0000');

        $statement = CardStatement::forAccount($this->card)->sole();

        // The issuer has not billed the pending one, so including it would
        // overstate what is due.
        $this->assertSame('80.0000', $statement->charged);
        $this->assertSame('80.0000', $statement->owed());
        // Which is why chargeCount counts one and not two. The pending row is not
        // dropped, it is moved to pendingCount -- a charge count that quietly
        // excluded one would make the period look final when it is not.
        $this->assertSame(1, $statement->chargeCount);
        $this->assertSame(1, $statement->pendingCount);
    }

    public function test_a_period_holding_a_pending_charge_says_so(): void
    {
        // The reason a period cannot be settled. It is not a hint: the figure is
        // correct without the pending charge, so a user who trusted the number
        // would pay 80 against a statement that will bill 200 once the pending
        // charge posts and the period reopens.
        $this->charge('2026-01-01', '120.0000', 'pending');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertTrue($statement->hasPendingActivity());
        $this->assertSame(1, $statement->pendingCount);
    }

    public function test_a_pending_payment_also_blocks_the_period(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->payment('2026-02-01', '10.0000', 'pending', '2026-02-09');

        // A pending *payment* is odd but not impossible -- entered before the
        // bank confirms it -- and it blocks the period just the same, because a
        // pending payment is money that may not have left.
        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertTrue($statement->hasPendingActivity());
        // The owed figure ignores it, so the 10 is not subtracted twice.
        $this->assertSame('120.0000', $statement->owed());
    }

    // ---------------------------------------------------------------------
    // What does not become a period
    // ---------------------------------------------------------------------

    public function test_a_cash_expense_with_no_bag_is_not_a_period(): void
    {
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        Transaction::create([
            'account_id' => $bank->id,
            'category_id' => null,
            'date' => '2026-01-01',
            'type' => 'withdraw',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        // For the card, and because the filter has to be the bag's due_date rather
        // than merely "this account is a card": a cash account has no card terms
        // and no period, and the join must not manufacture one.
        $this->assertSame(0, CardStatement::forAccount($bank)->count());
        $this->assertSame(0, CardStatement::forAccount($this->card)->count());
    }

    public function test_a_charge_on_a_card_with_no_statement_day_is_not_a_period(): void
    {
        // The derivation leaves due_date null when the card has no cycle, and the
        // controller drops nulls from the bag -- so the key is absent rather than
        // present-and-null. Both are tested because JSON_EXTRACT returns a JSON
        // null, not a SQL NULL, for the second: `IS NOT NULL` would be true for it
        // and the row would join as a period with no due date at all.
        $headless = Account::create(['name' => 'NoCycle', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $headless->meta()->create(['meta' => ['term_days' => 15]]);

        $this->chargeOn($headless, '2026-01-01', '120.0000', []);

        $this->assertSame(0, CardStatement::forAccount($headless)->count());
    }

    public function test_one_cards_periods_are_not_another_cards(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->chargeOn($this->otherCard, '2026-01-01', '999.0000', []);

        $this->assertSame('120.0000', CardStatement::forAccount($this->card)->sole()->owed());
        $this->assertSame('999.0000', CardStatement::forAccount($this->otherCard)->sole()->owed());
    }

    public function test_a_period_reports_the_charge_dates_it_covers(): void
    {
        // What the statement is for, as against when it is payable. Observed from the
        // rows rather than derived from the cycle, so a charge dated into the wrong
        // period shows the dates it actually landed on rather than the ones it should
        // have -- which is the difference worth seeing.
        $this->chargeOn($this->card, '2026-01-10', '120.0000');
        $this->chargeOn($this->card, '2026-01-20', '80.5000');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertSame('2026-01-10', $statement->firstChargeDate);
        $this->assertSame('2026-01-20', $statement->lastChargeDate);
    }

    public function test_a_payment_does_not_widen_the_span_a_period_covers(): void
    {
        // A payment is not something the statement is for. The MIN and MAX are over
        // charge rows only, so a payment dated outside the charges cannot stretch the
        // range to cover days that hold nothing.
        $this->chargeOn($this->card, '2026-01-10', '120.0000');
        $this->payment('2026-02-20', '120.0000', 'posted', '2026-02-09');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertSame('2026-01-10', $statement->firstChargeDate);
        $this->assertSame('2026-01-10', $statement->lastChargeDate);
    }

    public function test_a_pending_charge_is_in_the_period_it_belongs_to(): void
    {
        // Not filtered by status, unlike every figure beside it, and deliberately. A
        // pending charge is in this period -- it simply is not billed yet, which is
        // what pending_count and its badge are for. Filtering it would report a period
        // as not covering a day it plainly covers, and a period whose only charge is
        // pending as covering nothing at all.
        //
        // The 24th rather than the 25th: the 25th is the card's closing day, which is
        // the boundary between this statement and the next, so a charge on it is
        // billed by the following one. This is about a pending row staying inside the
        // period it is in, and which period that is has a test of its own.
        $this->chargeOn($this->card, '2026-01-10', '120.0000');
        $this->chargeOn($this->card, '2026-01-24', '40.0000', status: 'pending');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertSame('2026-01-10', $statement->firstChargeDate);
        $this->assertSame('2026-01-24', $statement->lastChargeDate);
        $this->assertSame(1, $statement->chargeCount, 'The pending charge is not counted toward the balance.');
    }

    public function test_a_period_with_no_charge_covers_nothing(): void
    {
        // Null rather than the payment's own date. A period can be named by a payment
        // with nothing charged to it, and reporting that date would say the statement
        // covers a day it does not.
        $this->payment('2026-02-20', '120.0000', 'posted', '2026-02-09');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertNull($statement->firstChargeDate);
        $this->assertNull($statement->lastChargeDate);
    }

    // ---------------------------------------------------------------------
    // The money is decimal, not float
    // ---------------------------------------------------------------------

    public function test_the_totals_keep_four_decimal_places(): void
    {
        // The column is decimal(12,4) and the arithmetic is done in MySQL, which is
        // exact for decimals. The figures are then read as strings and never
        // become floats, so a tenth of a cent cannot drift.
        $this->charge('2026-01-01', '0.1000');
        $this->charge('2026-01-02', '0.2000');

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertSame('0.3000', $statement->charged);
        $this->assertSame('0.3000', $statement->owed());
    }

    public function test_a_figure_wider_than_a_float_could_hold_is_exact(): void
    {
        // 0.1 + 0.2 is the canonical float failure, and it is invisible until a
        // balance is totalled over many rows. Asserted as an exact string.
        $this->charge('2026-01-01', '0.1000');
        $this->charge('2026-01-02', '0.2000');
        $this->charge('2026-01-03', '12345678.9000');

        $this->assertSame('12345679.2000', CardStatement::forAccount($this->card)->sole()->owed());
    }

    public function test_a_card_with_no_card_terms_has_no_periods_even_with_charges(): void
    {
        // Defence in depth against the shape rather than the rule: fromAccount()
        // is asked for a card, and a card without terms is a card the app will
        // refuse to place a charge in anyway. Returns empty rather than throwing,
        // matching CardStatementCycle::fromMeta.
        $headless = Account::create(['name' => 'Bare', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);

        $this->assertNull(CardStatementCycle::fromMeta($headless->meta?->meta));
        $this->assertSame(0, CardStatement::forAccount($headless)->count());
    }

    // ---------------------------------------------------------------------
    // The rows behind a period
    // ---------------------------------------------------------------------

    public function test_rows_in_period_returns_every_row_of_that_period_and_nothing_else(): void
    {
        // The read behind moveDueDate(). It has to pick out exactly the rows
        // forAccount() grouped, or a correction moves some of a period and leaves the
        // rest behind -- and a charge left in the old period is a charge the card is
        // billed for twice over, with the panel showing both.
        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-01-20', '80.5000');
        $this->payment('2026-02-20', '50.0000', 'posted', '2026-02-09');
        $this->charge('2026-01-26', '10.0000');

        $rows = CardStatement::rowsInPeriod($this->card, '2026-02-09');

        $this->assertCount(3, $rows);
        $this->assertSame(
            ['120.0000', '80.5000', '50.0000'],
            $rows->pluck('amount')->all(),
            'The period\'s rows are not the ones its figures were counted from.'
        );

        // Bags loaded, or the write below is a query per row on top of the read.
        $this->assertTrue($rows->every(fn ($row) => $row->relationLoaded('meta')));
    }

    public function test_rows_in_period_of_a_day_no_statement_falls_due_is_empty(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->assertCount(0, CardStatement::rowsInPeriod($this->card, '2026-06-09'));
    }

    public function test_rows_in_period_does_not_reach_another_cards_statements(): void
    {
        // Both cards close on different days, so the same day is a period on one and
        // nothing on the other. Picking rows by date without the account would move a
        // stranger's statement along with it.
        $this->charge('2026-01-01', '120.0000');
        $this->chargeOn($this->otherCard, '2026-01-01', '99.0000');

        // The other card closes on the 10th rather than the 25th, so the same charge
        // falls in a different period on it.
        $this->assertCount(1, CardStatement::rowsInPeriod($this->card, '2026-02-09'));
        $this->assertCount(1, CardStatement::rowsInPeriod($this->otherCard, '2026-01-25'));
    }

    // ---------------------------------------------------------------------

    private function card(string $name, array $terms): Account
    {
        $card = Account::create(['name' => $name, 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $card->meta()->create(['meta' => $terms]);

        return $card;
    }

    private function charge(string $date, string $amount, string $status = 'posted'): void
    {
        $this->chargeOn($this->card, $date, $amount, [], $status);
    }

    private function chargeOn(
        Account $account,
        string $date,
        string $amount,
        array $meta = [],
        string $status = 'posted'
    ): void {
        $this->row($account, $date, 'charge', $amount, $meta, $status);
    }

    private function payment(string $date, string $amount, string $status = 'posted', ?string $dueDate = null): void
    {
        $this->row($this->card, $date, 'payment', $amount, ['due_date' => $dueDate], $status);
    }

    private function row(
        Account $account,
        string $date,
        string $type,
        string $amount,
        array $meta,
        string $status
    ): void {
        $transaction = Transaction::create([
            'account_id' => $account->id,
            'category_id' => null,
            'date' => $date,
            'type' => $type,
            'description' => $type,
            'amount' => $amount,
            'ccy' => 'HKD',
            'status' => $status,
        ]);

        // A charge's due_date derived from the account's own terms, exactly as
        // TransactionData does it, rather than hardcoded to one date -- which is what
        // makes the multi-period tests mean anything. A payment's is supplied, since
        // naming the statement it settles is a question about what is outstanding.
        //
        // A card with no terms produces no due_date here without a special case,
        // because fromMeta() returns null; the null is then filtered out of the bag
        // and the key is absent rather than present-and-null, which is the case
        // JSON_EXTRACT in the query has to cope with.
        if ($type === 'charge' && ! array_key_exists('due_date', $meta)) {
            $cycle = CardStatementCycle::fromMeta($account->meta?->meta);

            $meta['due_date'] = $cycle?->dueDateFor(Carbon::parse($date))->toDateString();
        }

        $transaction->meta()->create([
            'meta' => array_filter($meta, fn ($value) => $value !== null),
        ]);
    }
}
