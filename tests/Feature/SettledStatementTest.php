<?php

namespace Tests\Feature;

use App\DTO\TransactionData;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\CardStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * What may still happen to a statement that has been paid.
 *
 * A settled period is the record of a bill: the charges it covered and the payment
 * that closed it. Anything that changes its figures afterwards -- a charge moved in or
 * out, added, deleted, or edited in place -- leaves the paid bill owing or in credit
 * with nothing to explain it, and the only way to reopen one is to delete the payment.
 * So each is refused with that way out, and the page says so before anyone tries.
 */
class SettledStatementTest extends TestCase
{
    use BuildsACard, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
    }

    // ---------------------------------------------------------------------
    // Moving a charge in or out
    // ---------------------------------------------------------------------

    public function test_a_charge_in_a_settled_statement_cannot_be_re_dated(): void
    {
        // A settled period is a bill that has been paid, and its figures are the record
        // of that bill. Moving the charge out would leave a credit against money already
        // handed over, which this app can represent as nothing but a corrupted
        // statement. There is no un-settling either, so the save is refused and the row
        // is left exactly as it was -- including its date, which is the field the error
        // is keyed on and the one the user moved.
        $transaction = $this->storedCharge();

        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'date' => '2026-01-26',
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasErrors('date');

        $fresh = $transaction->fresh();

        $this->assertSame('2026-01-01', $fresh->date, 'The rejected date was written anyway.');
        $this->assertSame('2026-02-09', $fresh->meta_data['due_date']);
        $this->assertTrue(
            CardStatement::forAccount($this->card)->firstWhere('dueDate', '2026-02-09')->isSettled(),
            'The statement the charge belongs to no longer balances.'
        );
    }

    public function test_the_refusal_to_re_date_names_a_way_out_that_works(): void
    {
        // The message used to say "delete it and record it again", and deleteRefusal()
        // turns that delete down for a charge in a settled statement -- so the advice
        // led to a second refusal. Followed as written, this one has to succeed.
        $transaction = $this->storedCharge();

        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'date' => '2026-01-26',
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasErrors([
            'date' => 'The statement due 2026-02-09 has been settled, so this charge cannot be moved out of it. '
                .'Delete the payment that settled it, move the charge, and settle it again.',
        ]);

        $payment = Transaction::where('type', 'payment')->firstOrFail();

        $this->delete("/transactions/{$payment->id}")->assertSessionHasNoErrors();

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'date' => '2026-01-26',
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasNoErrors();

        $this->settleTheStatementDue('2026-03-12', '120.0000');

        $this->assertTrue(CardStatement::forAccount($this->card)->firstWhere('dueDate', '2026-03-12')->isSettled());
    }

    public function test_a_charge_cannot_be_moved_into_a_settled_statement(): void
    {
        // The other direction, and the one a guard written only for the period being
        // left would miss: the charge stays in an open statement and is re-dated across
        // a boundary into one that has been paid, which makes a settled bill owing
        // money again.
        $this->storedCharge();

        // A second charge a statement later: 1 Mar is billed by the statement closing on
        // 25 Mar, so it falls due on 9 Apr.
        $this->post('/transactions', $this->chargePayload([
            'date' => '2026-03-01',
            'description' => 'Books',
        ]))->assertSessionHasNoErrors();

        $this->settleTheStatementDue('2026-04-09', '120.0000');

        $charge = Transaction::where('description', 'Cafe')->firstOrFail();

        $this->put("/transactions/{$charge->id}", $this->chargePayload([
            'date' => '2026-03-15',
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasErrors('date');

        $this->assertSame('2026-01-01', $charge->fresh()->date);
        $this->assertSame('2026-02-09', $charge->fresh()->meta_data['due_date']);
    }

    public function test_a_charge_cannot_leave_a_settled_statement_for_a_card_sharing_its_due_date(): void
    {
        // Two cards closing on the same day produce the same due dates, so the period a
        // charge would land in on the other card carries the date of the one it is
        // leaving. That is a different bill, and the paid one on this card would be left
        // showing a credit against money already handed over.
        $other = Account::create(['name' => 'Other', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $other->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);

        $transaction = $this->storedCharge();

        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'account_id' => $other->id,
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasErrors('date');

        $this->assertSame($this->card->id, $transaction->fresh()->account_id, 'The charge left its card anyway.');
        $this->assertTrue(
            CardStatement::forAccount($this->card)->firstWhere('dueDate', '2026-02-09')->isSettled(),
            'The statement the charge was moved out of no longer balances.'
        );
    }

    public function test_a_charge_with_no_period_cannot_be_re_dated_into_a_settled_statement(): void
    {
        // A charge recorded before its card had terms carries no period, so there is
        // nothing for it to leave -- and that is no reason to skip checking the one it
        // arrives in.
        $bare = Transaction::create([
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2025-12-01',
            'type' => 'charge',
            'description' => 'Bare',
            'amount' => '30.0000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $this->storedCharge();
        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->put("/transactions/{$bare->id}", $this->chargePayload([
            'date' => '2026-01-02',
            'description' => 'Bare',
            'amount' => '30.0000',
        ]))->assertSessionHasErrors('date');

        $this->assertSame('2025-12-01', $bare->fresh()->date);
    }

    // ---------------------------------------------------------------------
    // Changing a row that stays
    // ---------------------------------------------------------------------

    public function test_a_charge_in_a_settled_statement_keeps_its_amount(): void
    {
        // The charge stays in its period and its figure moves instead, which leaves the
        // paid bill owing the difference just as a move would -- and the panel is the
        // only place that shows.
        $transaction = $this->storedCharge();

        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'amount' => '150.0000',
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasErrors([
            'amount' => 'The statement due 2026-02-09 has been settled, so this charge\'s amount cannot be '
                .'changed. Delete the payment that settled it, make the change, and settle it again.',
        ]);

        $this->assertSame('120.0000', $transaction->fresh()->amount);
        $this->assertTrue(CardStatement::forAccount($this->card)->firstWhere('dueDate', '2026-02-09')->isSettled());
    }

    public function test_a_paid_charge_cannot_be_re_dated_even_within_its_statement(): void
    {
        // The 10th is in the same period as the 1st, so the move guard has nothing to
        // say. The date is locked anyway, because the form disables it and the server
        // must not accept what the form will not let anyone send.
        $transaction = $this->storedCharge();

        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'date' => '2026-01-10',
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasErrors('date');

        $this->assertSame('2026-01-01', $transaction->fresh()->date);
    }

    public function test_the_payment_that_settled_a_statement_cannot_be_marked_pending(): void
    {
        // A pending payment stops counting, and the statement it paid owes again.
        $this->storedCharge();
        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $payment = Transaction::where('type', 'payment')->firstOrFail();

        $this->put("/transactions/{$payment->id}", array_merge(
            TransactionData::from($payment->load('meta', 'account'))->toArray(),
            ['status' => 'pending']
        ))->assertSessionHasErrors([
            'status' => 'The statement due 2026-02-09 has been settled, so this payment\'s status cannot be '
                .'changed. Delete this payment and settle the statement again.',
        ]);

        $this->assertSame('posted', $payment->fresh()->status);
    }

    public function test_an_amount_written_differently_is_not_a_change(): void
    {
        // The column reads back at four places and a form may send fewer. Comparing the
        // strings would refuse a description fix on every paid charge typed as '120'.
        $transaction = $this->storedCharge();

        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'amount' => '120',
            'description' => 'Cafe, corrected',
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Cafe, corrected', $transaction->fresh()->description);
    }

    public function test_editing_a_charge_without_moving_it_leaves_its_statement_alone(): void
    {
        // The counterpart to the refusal above, and the reason the re-derivation is
        // gated on the date and the account having moved. A charge's statement is a
        // fact about the day it was made, so fixing a description has said nothing
        // about the period -- and a save that refused to touch the description of a
        // charge because its statement happened to be settled would be refusing an
        // edit the user never framed as a move.
        $transaction = $this->storedCharge();

        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'description' => 'Cafe, corrected',
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasNoErrors();

        $fresh = $transaction->fresh();

        $this->assertSame('Cafe, corrected', $fresh->description);
        $this->assertSame('2026-02-09', $fresh->meta_data['due_date']);
        $this->assertTrue(
            CardStatement::forAccount($this->card)->firstWhere('dueDate', '2026-02-09')->isSettled(),
            'Editing a description moved the charge out of the statement it was in.'
        );
    }

    public function test_editing_a_payment_keeps_the_statement_it_settles(): void
    {
        // The due date a payment carries is the one that is not derivable from its own
        // date: it names the bill that was paid, which is why settle() writes it and why
        // nothing recomputes it. A payment the form round-trips arrives with that period
        // in its bag, and dropping it would reopen a statement the user has already
        // discharged, leaving the panel owing what was paid a moment ago.
        $this->storedCharge();
        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $payment = Transaction::where('type', 'payment')->firstOrFail();

        $this->put("/transactions/{$payment->id}", [
            'account_id' => $this->card->id,
            'category_id' => null,
            'date' => '2026-02-09',
            'type' => 'payment',
            'description' => 'Statement paid',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => ['due_date' => '2026-02-09'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-02-09', $payment->fresh()->meta_data['due_date']);
        $this->assertTrue(
            CardStatement::forAccount($this->card)->firstWhere('dueDate', '2026-02-09')->isSettled(),
            'Editing a payment reopened the statement it settled.'
        );
    }

    // ---------------------------------------------------------------------
    // Recording a new charge
    // ---------------------------------------------------------------------

    public function test_a_new_charge_cannot_be_recorded_into_a_settled_statement(): void
    {
        // A charge found after the bill was paid, dated where it belongs. Accepting it
        // makes the paid statement owe money again, which the panel shows as a figure
        // with no explanation -- the same harm as moving a charge in, so the same answer.
        $this->storedCharge();
        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->post('/transactions', $this->chargePayload([
            'date' => '2026-01-02',
            'description' => 'Forgotten',
        ]))->assertSessionHasErrors('date');

        $this->assertSame(0, Transaction::where('description', 'Forgotten')->count());
        $this->assertTrue(
            CardStatement::forAccount($this->card)->firstWhere('dueDate', '2026-02-09')->isSettled(),
            'A new charge reopened a statement that has been paid.'
        );
    }

    public function test_a_new_charge_in_an_open_statement_is_still_recorded(): void
    {
        // The counterpart: one settled period on the card does not close the others.
        $this->storedCharge();
        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->post('/transactions', $this->chargePayload(['date' => '2026-01-26']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Transaction::where('type', 'charge')->count());
    }

    // ---------------------------------------------------------------------
    // Deleting
    // ---------------------------------------------------------------------

    public function test_a_charge_in_a_settled_statement_cannot_be_deleted(): void
    {
        // A settled period is the record of a bill that was paid. Deleting one of the
        // charges on it leaves the payment that closed it explaining less than the
        // money that left the bank, or nothing at all -- a period with no charges reads
        // as a credit, and the panel offers a settle button the server refuses.
        $charge = $this->storedCharge();

        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->delete("/transactions/{$charge->id}")
            ->assertSessionHas(
                'message',
                'Charge [Cafe] is in the statement due 2026-02-09, which has been settled, and cannot be '
                    .'deleted on its own. Delete the payment that settled it first.'
            );

        $this->assertSame('2026-01-01', $charge->fresh()->date, 'The charge was deleted anyway.');
        $this->assertSame('2026-02-09', $charge->fresh()->meta_data['due_date']);
        $this->assertTrue(
            CardStatement::forAccount($this->card)->sole()->isSettled(),
            'The statement no longer balances.'
        );
    }

    public function test_deleting_the_payment_first_makes_the_charge_deletable(): void
    {
        // The way out, and the reason the refusal is allowed to exist. A guard with no
        // exit makes a mistake in a paid statement uncorrectable for good; this is
        // undo the settlement, then fix the charge, one row at a time. Nothing else
        // would make a second charge on the same period deletable either.
        $charge = $this->storedCharge();
        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->delete('/transactions/'.Transaction::where('type', 'payment')->value('id'))
            ->assertSessionHasNoErrors();

        $this->delete("/transactions/{$charge->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0, CardStatement::forAccount($this->card)->count());
    }

    public function test_a_charge_in_an_open_statement_deletes_normally(): void
    {
        // The other half, so the guard is not refusing every charge on a card.
        $charge = $this->storedCharge();

        $this->delete("/transactions/{$charge->id}")
            ->assertSessionHas('message', 'Transaction [charge] deleted');
    }

    public function test_a_partly_paid_statement_still_lets_its_charges_go(): void
    {
        // Settled, not "has a payment in it". A period with 120 of charges and 50 paid
        // still owes 70, and the user is still going to pay the rest, so refusing to
        // correct a charge in it would be refusing a statement that is not closed.
        $charge = $this->storedCharge();

        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => null,
            'date' => '2026-01-15',
            'type' => 'payment',
            'description' => 'Part payment',
            'amount' => '50.0000',
            'ccy' => 'HKD',
            'meta_data' => ['due_date' => '2026-02-09'],
        ])->assertSessionHasNoErrors();

        $this->delete("/transactions/{$charge->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('transactions', ['id' => $charge->id]);
    }

    // ---------------------------------------------------------------------
    // What the page says before anyone tries
    // ---------------------------------------------------------------------

    public function test_index_tells_the_edit_form_which_figures_each_row_cannot_change(): void
    {
        // Sent so the form can say so before a save is refused, from the same decision
        // the refusal makes. Every row a settlement touches is fixed: the charge it
        // covered, the payment that closed it, and the bank's transfer, which no
        // statement lists. A charge in a period still owing is not.
        $paid = $this->storedCharge();
        $this->settleTheStatementDue('2026-02-09', '120.0000');

        $this->post('/transactions', $this->chargePayload(['date' => '2026-01-26']))->assertSessionHasNoErrors();
        $open = Transaction::latest('id')->firstOrFail();

        $payment = Transaction::where('type', 'payment')->firstOrFail();
        $transfer = Transaction::where('type', 'withdraw')->firstOrFail();

        $figures = ['account_id', 'type', 'amount', 'ccy', 'status'];

        // Payments asked for by type: the list hides a card payment otherwise.
        $this->get('/transactions?per_page=10&filter[type]=charge,payment,withdraw')->assertInertia(fn (Assert $page) => $page
            ->where("editLocks.{$paid->id}.fields", [
                'account_id', 'type', 'date', 'amount', 'ccy', 'status', 'meta_data.card_amount',
            ])
            ->where(
                "editLocks.{$paid->id}.message",
                'The statement due 2026-02-09 has been settled, so this charge\'s account, type, date, '
                    .'amount, currency, status and amount in the card\'s currency are fixed. Delete the '
                    .'payment that settled it, make the change, and settle it again.'
            )
            ->where("editLocks.{$payment->id}.fields", $figures)
            ->where("editLocks.{$transfer->id}.fields", $figures)
            ->where(
                "editLocks.{$transfer->id}.message",
                'This withdraw is one half of a card settlement, so its account, type, amount, currency '
                    .'and status are fixed to match the other half. Delete the settlement and settle the '
                    .'statement again.'
            )
            ->missing("editLocks.{$open->id}")
        );
    }

    public function test_index_says_which_rows_cannot_be_deleted_and_why(): void
    {
        // The button is disabled from this, so the sentence has to be the one destroy()
        // would have refused with -- the browser displays it rather than composing its
        // own, and a charge in a settled statement is the only thing on this list that
        // carries a reason.
        $this->card->meta()->update(['meta' => [
            'term_days' => 15,
            'statement_day' => 25,
            'settlement_account_id' => $this->bank->id,
        ]]);

        $charge = $this->storedCharge();

        $this->post("/accounts/{$this->card->id}/settle", [
            'due_date' => '2026-02-09',
            'owed' => '120.0000',
        ])->assertSessionHasNoErrors();

        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'date' => '2026-01-10',
            'type' => 'withdraw',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('refusals', [$charge->id => 'Charge [Cafe] is in the statement due 2026-02-09, which has been settled, and cannot be '
                    .'deleted on its own. Delete the payment that settled it first.',
            ])
        );
    }

    // ---------------------------------------------------------------------

    /** A charge recorded through the form on 1 Jan, in the statement due PERIOD. */
    private function storedCharge(): Transaction
    {
        $this->post('/transactions', $this->chargePayload())->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function settleTheStatementDue(string $dueDate, string $owed): void
    {
        $this->settle(['due_date' => $dueDate, 'owed' => $owed])->assertSessionHasNoErrors();
    }
}
