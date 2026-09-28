<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\CardStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * Replacing a statement period's due date with the day the bank stated.
 *
 * The day a period carries is counted from the card's statement day and term, and the
 * bank's own day is not always that one. So the prediction is a standing assumption and
 * the statement in the user's hand is the fact, and this is the way the fact wins.
 *
 * A period is not a row. The due date is the key every charge and payment in the period
 * shares, which is what makes this a bulk write over bags and not an update, and what
 * makes the refusals below matter: the same key is what the panel groups by, so a
 * correction that landed on a day that is already a period would merge two bills into
 * one row and leave a single settle paying both.
 */
class CardStatementDueDateTest extends TestCase
{
    use BuildsACard, RefreshDatabase;

    /** A charge on 26 Jan closes the following month, due 12 Mar. */
    private const LATER = '2026-03-12';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
    }

    // ---------------------------------------------------------------------
    // The period moves as a whole
    // ---------------------------------------------------------------------

    public function test_every_row_in_the_period_moves_to_the_stated_day(): void
    {
        $first = $this->charge('2026-01-01', '120.0000');
        $second = $this->charge('2026-01-10', '80.0000');
        $payment = $this->payment('2026-02-01', '50.0000', self::PERIOD);

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasNoErrors();

        // All three, or the period is no longer a period: the payment left behind under
        // the old key would be a period of its own with nothing charged to it, and the
        // panel would show a bill owing nothing beside one owing the full amount.
        $this->assertSame('2026-02-20', $this->dueDateOf($first));
        $this->assertSame('2026-02-20', $this->dueDateOf($second));
        $this->assertSame('2026-02-20', $this->dueDateOf($payment));

        $periods = CardStatement::forAccount($this->card);

        $this->assertCount(1, $periods, 'The period split rather than moving.');
        $this->assertSame('2026-02-20', $periods->first()->dueDate);
        $this->assertSame('150.0000', $periods->first()->owed());
    }

    public function test_another_period_on_the_same_card_is_left_alone(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $later = $this->charge('2026-01-26', '80.0000');

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasNoErrors();

        $this->assertSame(
            self::LATER,
            $this->dueDateOf($later),
            'A charge in another period was moved, so the card is re-billed for it.'
        );
    }

    public function test_a_charge_filed_under_no_period_is_not_swept_up(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $unfiled = Transaction::create([
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-05',
            'type' => 'charge',
            'description' => 'Unfiled',
            'amount' => '10.0000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasNoErrors();

        $this->assertNull(
            $this->dueDateOf($unfiled),
            'A charge with no bag was given a period, which puts it in a statement it was never counted on.'
        );
    }

    public function test_a_charge_on_another_card_is_not_moved(): void
    {
        $other = Account::create(['name' => 'Other', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $other->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);

        $theirs = $this->chargeOn($other, '2026-01-01', '99.0000');
        $ours = $this->charge('2026-01-01', '120.0000');

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasNoErrors();

        $this->assertSame(self::PERIOD, $this->dueDateOf($theirs));
        $this->assertSame('2026-02-20', $this->dueDateOf($ours));
    }

    // ---------------------------------------------------------------------
    // What else the bag carries
    // ---------------------------------------------------------------------

    public function test_the_card_currency_figure_survives_the_move(): void
    {
        // A charge in another currency, filed with what it is worth to the card. The
        // statement totals that figure rather than the row's own amount, so a write that
        // replaced the bag instead of merging would change what the card is owed by 780
        // with nothing saying the amount had moved.
        $charge = Transaction::create([
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Books',
            'amount' => '100.0000',
            'ccy' => 'USD',
            'status' => 'posted',
        ]);

        $charge->meta()->create([
            'meta' => ['due_date' => self::PERIOD, 'card_amount' => '780.0000'],
        ]);

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasNoErrors();

        $this->assertSame('780.0000', $charge->fresh()->meta?->meta['card_amount'] ?? null);

        $this->assertSame(
            '780.0000',
            CardStatement::forAccount($this->card)->first()->charged,
            'The corrected statement totals the amount rather than the figure the card is owed.'
        );
    }

    public function test_the_settlement_pairing_survives_the_move(): void
    {
        // The link is the whole of what makes deleting a settlement delete both halves,
        // and it lives in the same bag as the due date. A write that set one key would
        // leave a payment on the card that no longer knows about its transfer.
        $payment = $this->payment('2026-02-01', '50.0000', self::PERIOD);
        $payment->meta()->update(['meta' => array_merge(
            $payment->meta->meta->getArrayCopy(),
            ['paired_transaction_id' => 4242]
        )]);

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasNoErrors();

        $this->assertSame(4242, $payment->fresh()->meta?->meta['paired_transaction_id'] ?? null);
    }

    // ---------------------------------------------------------------------
    // Refusals
    // ---------------------------------------------------------------------

    public function test_a_settled_statement_is_refused(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->payment('2026-02-01', '120.0000', self::PERIOD);

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasErrors('due_date');

        $this->assertSame(
            self::PERIOD,
            CardStatement::forAccount($this->card)->first()->dueDate,
            'A paid statement was renamed, so the payment no longer explains the bill it settled.'
        );
    }

    public function test_a_statement_with_rows_not_yet_posted_is_refused(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-01-10', '10.0000', 'pending');

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasErrors('due_date');

        $this->assertSame(
            self::PERIOD,
            CardStatement::forAccount($this->card)->first()->dueDate,
            'A statement that had not been issued was given a due date, dragging a row the '
                .'bank had not billed onto a statement that says nothing about it.'
        );
    }

    public function test_a_day_that_is_already_a_statement_is_refused(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-01-26', '80.0000');

        $this->correct(self::PERIOD, self::LATER)->assertSessionHasErrors('due_date');

        // The two bills are still two. Merged, the panel would show one period owing
        // 200.0000 and a single settle would write one payment against it.
        $periods = CardStatement::forAccount($this->card);

        $this->assertCount(2, $periods, 'Two statements were merged into one.');
        $this->assertSame([self::PERIOD, self::LATER], $periods->pluck('dueDate')->all());
    }

    public function test_a_day_on_or_before_the_last_charge_is_refused(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->correct(self::PERIOD, '2026-01-01')->assertSessionHasErrors('due_date');
        $this->correct(self::PERIOD, '2025-12-31')->assertSessionHasErrors('due_date');

        $this->assertSame(self::PERIOD, CardStatement::forAccount($this->card)->first()->dueDate);
    }

    public function test_an_account_that_is_not_a_card_is_refused(): void
    {
        $this->post("/accounts/{$this->bank->id}/due-date", [
            'due_date' => self::PERIOD,
            'new_due_date' => '2026-02-20',
        ])->assertSessionHasErrors('due_date');
    }

    public function test_a_statement_that_does_not_exist_is_refused(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->correct('2026-06-09', '2026-06-20')->assertSessionHasErrors('due_date');
    }

    public function test_a_malformed_day_is_refused_before_anything_is_read(): void
    {
        $charge = $this->charge('2026-01-01', '120.0000');

        $this->correct(self::PERIOD, '20/02/2026')->assertSessionHasErrors('new_due_date');

        $this->assertSame(self::PERIOD, $this->dueDateOf($charge));
    }

    // ---------------------------------------------------------------------
    // The ordinary cases
    // ---------------------------------------------------------------------

    public function test_moving_a_statement_to_the_day_it_already_has_changes_nothing(): void
    {
        $charge = $this->charge('2026-01-01', '120.0000');

        $this->correct(self::PERIOD, self::PERIOD)->assertSessionHasNoErrors();

        $this->assertSame(self::PERIOD, $this->dueDateOf($charge));
    }

    public function test_the_cards_terms_are_left_alone(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasNoErrors();

        // The terms are the standing assumption this corrects one period against, and
        // rewriting them from a correction would re-derive every charge on the card --
        // a year of history moved by correcting last month's bill.
        $this->assertSame(
            ['term_days' => 15, 'statement_day' => 25, 'settlement_account_id' => $this->bank->id],
            $this->card->fresh()->meta?->meta->getArrayCopy()
        );
    }

    public function test_the_panel_shows_the_stated_day(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasNoErrors();

        $this->get('/transactions')
            ->assertInertia(fn (Assert $page) => $page
                ->has('statements.0.periods', 1)
                ->where('statements.0.periods.0.due_date', '2026-02-20')
                ->where('statements.0.periods.0.owed', '120.0000')
            );
    }

    public function test_a_corrected_statement_can_then_be_settled(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->correct(self::PERIOD, '2026-02-20')->assertSessionHasNoErrors();

        $this->post("/accounts/{$this->card->id}/settle", [
            'due_date' => '2026-02-20',
            'owed' => '120.0000',
        ])->assertSessionHasNoErrors();

        // Settling the corrected period leaves it settled under the corrected key, and
        // nothing under the old one. A payment written against the day the period used
        // to carry would sit in an empty period owing the full amount forever.
        $periods = CardStatement::forAccount($this->card);

        $this->assertCount(1, $periods);
        $this->assertSame('2026-02-20', $periods->first()->dueDate);
        $this->assertTrue($periods->first()->isSettled());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function correct(string $dueDate, string $newDueDate)
    {
        return $this->post("/accounts/{$this->card->id}/due-date", [
            'due_date' => $dueDate,
            'new_due_date' => $newDueDate,
        ]);
    }

    private function dueDateOf(Transaction $transaction): ?string
    {
        return $transaction->fresh()->meta?->meta?->getArrayCopy()['due_date'] ?? null;
    }
}
