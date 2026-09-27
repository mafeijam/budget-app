<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Meta;
use App\Models\Transaction;
use App\Support\CardStatement;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Settling a card statement period, which writes two rows.
 *
 * A settlement is not one transaction. It is a payment on the card, which reduces
 * what the card owes, and a transfer out of the bank the card is paid from, which
 * is where the money actually went. Both the same amount, both written together or
 * neither.
 *
 * The amount is computed here and never taken from the request. The one thing the
 * client does send is the figure the user was shown, and that is compared rather
 * than used: a settlement made against a figure that has since changed would record
 * a payment the user never agreed to, and the two-row write would then be internally
 * consistent and wrong.
 */
class CardSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $card;

    private int $category;

    private const PERIOD = '2026-02-09';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->card = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $this->card->meta()->create([
            'meta' => ['term_days' => 15, 'statement_day' => 25, 'settlement_account_id' => $this->bank->id],
        ]);
        $this->category = DB::table('categories')->insertGetId(['name' => 'FOOD']);
    }

    // ---------------------------------------------------------------------
    // The day the money moved
    // ---------------------------------------------------------------------

    public function test_the_day_the_money_moved_is_the_users_to_say(): void
    {
        // Settling on the day a statement falls due is the exception, not the rule:
        // people pay on the day they are reminded. And a settlement forgotten last
        // week is a real thing to want to be able to record rather than date today.
        $this->charge('2026-01-01', '120.0000');

        $this->settle([
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
            'date' => '2026-01-28',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-01-28', Transaction::where('type', 'payment')->firstOrFail()->date);
    }

    public function test_both_rows_carry_the_same_day(): void
    {
        // A settlement is one act, and the two rows describe it -- the same reasoning
        // that gives them the same amount. Two dates would be two facts about one
        // event, and the transfer would claim the money left on a different day than
        // the card says it came back.
        $this->charge('2026-01-01', '120.0000');

        $this->settle([
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
            'date' => '2026-01-28',
        ])->assertSessionHasNoErrors();

        $payment = Transaction::where('type', 'payment')->firstOrFail();
        $transfer = Transaction::where('type', 'transfer')->firstOrFail();

        $this->assertSame($payment->date, $transfer->date);
        $this->assertSame('2026-01-28', $payment->date);
    }

    public function test_a_settlement_with_no_day_given_is_dated_the_period_it_settles(): void
    {
        // What every caller gets when it omits the day, and what the dialog pre-fills.
        // The statement's own due date rather than today, because a settlement belongs
        // to the period it settles -- and pinned because a default that disagrees with
        // the form's would mean the dialog shows one day and the endpoint writes
        // another, which is the kind of thing only a test notices.
        //
        // Today() would be the other defensible answer, and it is wrong here in a way
        // that shows up on real data: a period can be outstanding before it falls due,
        // so defaulting to today would date an early payment to whenever the user
        // happened to settle it.
        $this->charge('2026-01-01', '120.0000');

        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            self::PERIOD,
            Transaction::where('type', 'payment')->firstOrFail()->date,
            'A settlement with no day given should carry the statement due date.'
        );
    }

    public function test_a_malformed_day_is_refused_and_writes_nothing(): void
    {
        // Validation runs before the transaction opens, so nothing is written at all --
        // not one row, and not half a pair.
        $this->charge('2026-01-01', '120.0000');

        $this->settle([
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
            'date' => '28/01/2026',
        ])->assertSessionHasErrors('date');

        $this->assertDatabaseCount('transactions', 1); // the charge, and no pair
    }

    public function test_the_day_does_not_move_the_period_it_settles(): void
    {
        // The bag's due_date is the statement query's grouping key, and the row's date
        // is not. Backdating the payment must not re-file it into an earlier period --
        // a settlement is applied to the period the user named, whatever day they say
        // they paid.
        $this->charge('2026-01-01', '120.0000');

        $this->settle([
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
            'date' => '2025-12-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            self::PERIOD,
            Transaction::where('type', 'payment')->firstOrFail()->meta_data['due_date']
        );
    }

    // ---------------------------------------------------------------------
    // The two rows
    // ---------------------------------------------------------------------

    public function test_settling_a_period_writes_a_payment_and_a_transfer(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-01-20', '80.5000');

        $response = $this->settle(['due_date' => self::PERIOD, 'owed' => '200.5000']);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', 'Card statement [2026-02-09] settled: 200.5000 HKD');

        $this->assertDatabaseCount('transactions', 4); // two charges, and the pair

        $payment = Transaction::where('type', 'payment')->firstOrFail();
        $transfer = Transaction::where('type', 'transfer')->firstOrFail();

        $this->assertSame($this->card->id, $payment->account_id);
        $this->assertSame('200.5000', $payment->amount);
        $this->assertSame('HKD', $payment->ccy);
        // The period is named on the card side, because that is the key the statement
        // query groups by.
        $this->assertSame(self::PERIOD, $payment->meta_data['due_date']);

        $this->assertSame($this->bank->id, $transfer->account_id);
        $this->assertSame('200.5000', $transfer->amount);
        $this->assertSame('HKD', $transfer->ccy);
        // And on the bank side there is no statement period, because a bank has no
        // statements. A due_date here would put the transfer in a card's arithmetic.
        $this->assertNull($transfer->meta?->meta?->getArrayCopy()['due_date'] ?? null);
    }

    public function test_the_two_rows_point_at_each_other(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $payment = Transaction::where('type', 'payment')->firstOrFail();
        $transfer = Transaction::where('type', 'transfer')->firstOrFail();

        $this->assertSame($transfer->id, (int) $payment->meta_data['paired_transaction_id']);
        $this->assertSame($payment->id, (int) $transfer->meta_data['paired_transaction_id']);
    }

    public function test_settling_leaves_the_period_reading_zero(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertSame('0.0000', $statement->owed());
        $this->assertTrue($statement->isSettled());
    }

    public function test_settling_leaves_the_other_periods_alone(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-01-26', '80.0000'); // a different period

        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $outstanding = CardStatement::outstandingFor($this->card);

        $this->assertSame(1, $outstanding->count());
        $this->assertSame('2026-03-12', $outstanding->sole()->dueDate);
        $this->assertSame('80.0000', $outstanding->sole()->owed());
    }

    public function test_a_partial_prior_payment_is_settled_for_the_remainder(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->payment('2026-01-15', '20.0000', self::PERIOD);

        $this->settle(['due_date' => self::PERIOD, 'owed' => '100.0000'])->assertSessionHasNoErrors();

        $this->assertSame(
            '100.0000',
            Transaction::where('type', 'payment')->latest('id')->firstOrFail()->amount
        );
        $this->assertTrue(CardStatement::forAccount($this->card)->sole()->isSettled());
    }

    // ---------------------------------------------------------------------
    // The amount is the server's
    // ---------------------------------------------------------------------

    public function test_the_amount_written_is_the_servers_figure_not_the_clients(): void
    {
        $this->charge('2026-01-01', '120.0000');

        // The client says it owes nothing; the server says 120. The server's figure is
        // what gets written, and the request is refused rather than settled for the
        // lesser number -- a settlement of 0.0000 would be a row pair recording
        // nothing, which is not what anyone meant.
        $this->settle(['due_date' => self::PERIOD, 'owed' => '0.0000'])
            ->assertSessionHasErrors('due_date');

        $this->assertDatabaseCount('transactions', 1); // the charge only
    }

    public function test_a_figure_that_has_moved_is_refused_with_the_new_one(): void
    {
        $this->charge('2026-01-01', '120.0000');

        // The dialog was opened showing 120, and a charge landed before the user
        // confirmed. Settling the figure they agreed to would record a payment
        // against a statement that has since grown.
        $this->charge('2026-01-02', '30.0000');

        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])
            ->assertSessionHasErrors([
                'due_date' => 'That statement now owes 150.0000 HKD, not 120.0000. Check the figure and confirm again.',
            ]);

        $this->assertDatabaseCount('transactions', 2); // the two charges only
    }

    public function test_a_matching_figure_settles_even_though_the_client_supplied_it(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $this->assertSame('120.0000', Transaction::where('type', 'payment')->firstOrFail()->amount);
    }

    public function test_a_second_settlement_of_the_same_period_writes_nothing(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 3);

        // A double click. The first settlement zeroed the period, so the second
        // recomputes to nothing owing and is refused -- which is why the figure is
        // recomputed rather than taken from the click.
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])
            ->assertSessionHasErrors('due_date');

        $this->assertDatabaseCount('transactions', 3);
    }

    // ---------------------------------------------------------------------
    // What cannot be settled
    // ---------------------------------------------------------------------

    public function test_a_period_with_nothing_in_it_cannot_be_settled(): void
    {
        $this->settle(['due_date' => self::PERIOD, 'owed' => '0.0000'])
            ->assertSessionHasErrors(['due_date' => 'Nothing is owed for the statement due 2026-02-09.']);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_period_holding_a_pending_charge_cannot_be_settled(): void
    {
        $this->charge('2026-01-01', '120.0000', 'posted');
        $this->charge('2026-01-02', '80.0000', 'pending');

        // The owed figure is 120, which is arithmetically right -- the issuer has not
        // billed the pending one. But the statement is not final, and settling 120
        // against a bill that will be 200 leaves the period owing 80 under someone who
        // believes they have paid it.
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])
            ->assertSessionHasErrors([
                'due_date' => 'That statement has 1 row not yet posted, so its total is not final. Post or remove it first.',
            ]);

        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_a_card_with_no_bank_named_cannot_be_settled(): void
    {
        $orphan = Account::create(['name' => 'Orphan', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $orphan->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);
        $this->chargeOn($orphan, '2026-01-01', '120.0000');

        $this->post("/accounts/{$orphan->id}/settle", [
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
        ])->assertSessionHasErrors([
            'due_date' => 'Card [Orphan] does not name the bank it is paid from, so it cannot be settled.',
        ]);

        $this->assertSame(1, Transaction::count());
    }

    public function test_a_cash_account_cannot_be_settled(): void
    {
        $this->post("/accounts/{$this->bank->id}/settle", [
            'due_date' => self::PERIOD,
            'owed' => '100.0000',
        ])->assertSessionHasErrors('due_date');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_period_that_does_not_exist_cannot_be_settled(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->settle(['due_date' => '2027-01-01', 'owed' => '120.0000'])
            ->assertSessionHasErrors(['due_date' => 'Nothing is owed for the statement due 2027-01-01.']);

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_a_period_without_a_due_date_is_rejected_by_the_rule(): void
    {
        $this->post("/accounts/{$this->card->id}/settle", ['owed' => '120.0000'])
            ->assertSessionHasErrors('due_date');

        $this->assertDatabaseCount('transactions', 0);
    }

    // ---------------------------------------------------------------------
    // The pair is not two independent rows
    //
    // Which is why deleting one of them deletes both. The browser is told what the
    // other half is before the user agrees; the server does not wait to be asked
    // twice, and will not leave half of one behind.
    // ---------------------------------------------------------------------

    public function test_a_paired_row_deletes_both_halves(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $payment = Transaction::where('type', 'payment')->firstOrFail();
        $transfer = Transaction::where('type', 'transfer')->firstOrFail();

        // Deleting one half used to be refused, which protected the pair by refusing
        // the only thing the user came for. Both rows go instead -- which is the same
        // protection, since the settlement either exists in full or not at all.
        $this->delete("/transactions/{$payment->id}")
            ->assertSessionHas('message', 'Card settlement ['.self::PERIOD.'] deleted in full: 2 transactions');

        // The charge is not part of the settlement and is still here, so the count is
        // one rather than nothing: a settlement is two rows, not a statement.
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseMissing('transactions', ['id' => $transfer->id]);

        // Bags too. An orphaned bag is not visible in a transaction list, but it is a
        // row a statement query joins against.
        $this->assertSame(1, Meta::where('model_type', Transaction::class)->count());
    }

    public function test_the_other_half_deletes_both_too(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $payment = Transaction::where('type', 'payment')->firstOrFail();
        $transfer = Transaction::where('type', 'transfer')->firstOrFail();

        // From the other end, and with the same message. The due date is read from
        // whichever half carries one -- only the card side is filed under a period --
        // so a settlement must not describe itself differently depending on which
        // half the user happened to click delete on.
        $this->delete("/transactions/{$transfer->id}")
            ->assertSessionHas('message', 'Card settlement ['.self::PERIOD.'] deleted in full: 2 transactions');

        $this->assertDatabaseMissing('transactions', ['id' => $payment->id]);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_deleting_a_settlement_reopens_the_statement_it_closed(): void
    {
        // The consequence, and the point. The charges are untouched, so removing the
        // payment leaves the period owing what it owed before it was paid -- which is
        // what un-doing a settlement means, and it means the card can be settled again
        // for the same figure.
        $this->charge('2026-01-01', '120.0000');
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $this->assertTrue(CardStatement::forAccount($this->card)->sole()->isSettled());

        $this->delete('/transactions/'.Transaction::where('type', 'payment')->value('id'))
            ->assertSessionHasNoErrors();

        $statement = CardStatement::forAccount($this->card)->sole();

        $this->assertFalse($statement->isSettled());
        $this->assertSame('120.0000', $statement->owed());
        $this->assertSame(0, $statement->paymentCount);
        $this->assertSame(1, $statement->chargeCount, 'The charge the payment settled is still in the period.');
    }

    public function test_a_row_whose_partner_is_gone_deletes_on_its_own(): void
    {
        // A pairing left behind by a row deleted outside this app. The link resolves to
        // nothing, and the alternative -- refusing, or erroring on the missing row --
        // would leave this row undeletable forever, which is a worse answer than
        // deleting the one row that is actually there.
        $orphan = Transaction::create([
            'account_id' => $this->card->id,
            'category_id' => null,
            'date' => '2026-02-09',
            'type' => 'payment',
            'description' => 'Payment',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $orphan->meta()->create(['meta' => ['paired_transaction_id' => 9999]]);

        $this->delete("/transactions/{$orphan->id}")
            ->assertSessionHas('message', 'Transaction [payment] deleted');

        $this->assertDatabaseMissing('transactions', ['id' => $orphan->id]);
    }

    public function test_an_unpaired_row_deletes_normally(): void
    {
        $charge = $this->charge('2026-01-01', '120.0000');

        $this->delete("/transactions/{$charge->id}")
            ->assertSessionHas('message', 'Transaction [charge] deleted');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_client_cannot_forge_a_pair(): void
    {
        $first = $this->charge('2026-01-01', '120.0000');
        $second = $this->charge('2026-01-02', '80.0000');

        // The pairing is written by settle() straight to the bag, and the DTO refuses
        // the field outright -- so a client claiming a pair gets rejected rather than
        // a row that points at somebody else's transaction.
        $this->post('/transactions', $this->chargePayload([
            'meta_data' => ['paired_transaction_id' => $first->id],
        ]))->assertSessionHasErrors('meta_data.paired_transaction_id');

        // The charge keeps its own bag -- the due_date the card's terms gave it --
        // but nothing in it is a pair, and no row was created for the rejected
        // request. The assertion is about the pair specifically: a bag is expected
        // here, a forged link inside it is not.
        $this->assertArrayNotHasKey(
            'paired_transaction_id',
            $second->fresh()->meta_data,
            'A client-supplied pair reached the bag.'
        );
        $this->assertSame(self::PERIOD, $second->fresh()->meta_data['due_date']);
        $this->assertSame(2, Transaction::count());
    }

    public function test_the_pair_is_written_even_though_the_dto_forbids_the_field(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        // The prohibition is on the payload, not on the column. settle() writes the
        // bag itself, which is the only way a field the client may not name can still
        // be recorded.
        $payment = Transaction::where('type', 'payment')->firstOrFail();

        $this->assertNotNull($payment->meta);
        $this->assertArrayHasKey('paired_transaction_id', $payment->meta_data);
    }

    // ---------------------------------------------------------------------
    // Both rows or neither
    // ---------------------------------------------------------------------

    public function test_a_failure_between_the_two_rows_writes_neither(): void
    {
        $this->charge('2026-01-01', '120.0000');

        // Refused from inside the write, after the payment exists: the transaction has
        // to roll back or the card shows a payment with no money having left.
        Transaction::creating(function (Transaction $transaction) {
            if ($transaction->type === 'transfer') {
                throw new \RuntimeException('simulated failure on the second write');
            }
        });

        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])
            ->assertSessionHas('message', 'error db...');

        Transaction::flushEventListeners();

        $this->assertSame(1, Transaction::count());
        $this->assertSame(0, Transaction::whereIn('type', ['payment', 'transfer'])->count());
        $this->assertSame('120.0000', CardStatement::forAccount($this->card)->sole()->owed());
    }

    // ---------------------------------------------------------------------

    private function settle(array $payload)
    {
        return $this->post("/accounts/{$this->card->id}/settle", $payload);
    }

    private function charge(string $date, string $amount, string $status = 'posted'): Transaction
    {
        return $this->chargeOn($this->card, $date, $amount, $status);
    }

    private function chargeOn(Account $card, string $date, string $amount, string $status = 'posted'): Transaction
    {
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

        // Derived from the card's own terms rather than hardcoded, so a charge that
        // falls after the closing day lands in a different period -- which is the only
        // way to test that settling one period leaves the others alone.
        $cycle = CardStatementCycle::fromMeta($card->meta?->meta);

        $transaction->meta()->create([
            'meta' => ['due_date' => $cycle?->dueDateFor(Carbon::parse($date))->toDateString()],
        ]);

        return $transaction;
    }

    private function payment(string $date, string $amount, string $dueDate): void
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
    }

    private function chargePayload(array $overrides = []): array
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
}
