<?php

namespace Tests\Feature;

use App\DTO\TransactionData;
use App\Models\Account;
use App\Models\Meta;
use App\Models\Transaction;
use App\Support\CardStatement;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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
    // Where the money leaves from
    // ---------------------------------------------------------------------

    public function test_a_card_with_no_bank_is_settled_from_the_account_the_user_names(): void
    {
        // A card carrying no link is a state the rules permit, not broken data --
        // AccountMetaData requires a settlement account of a brokerage and merely allows
        // one on a card, for exactly the window before the user has said where they pay
        // it from. So the dialog offers the choice and this endpoint takes it, rather
        // than the card being unsettleable until someone visits the account form.
        $other = Account::create(['name' => 'Reserve', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->card->meta()->update(['meta' => ['term_days' => 15, 'statement_day' => 25]]);

        $this->charge('2026-01-01', '120.0000');

        $this->settle([
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
            'settlement_account_id' => $other->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            $other->id,
            Transaction::where('type', 'transfer')->firstOrFail()->account_id,
            'The money did not leave the account the user named.'
        );
    }

    public function test_the_account_named_at_settlement_becomes_the_cards_bank(): void
    {
        // Remembered, or the picker would be asked for the same answer at every
        // statement and the card would still be sitting there with no bank. Only
        // when it differs, so the ordinary settle does not rewrite the row every time.
        $other = Account::create(['name' => 'Reserve', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-03-01', '80.0000');

        $this->settle([
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
            'settlement_account_id' => $other->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($other->id, $this->card->fresh()->settlementAccount()?->id);

        // The card's terms share the row, and dropping them would leave a card that
        // produces no due dates at all -- silently, since a charge would then simply
        // have no period.
        $this->assertSame(15, $this->card->meta->meta['term_days']);
        $this->assertSame(25, $this->card->meta->meta['statement_day']);

        // The second settle names nothing, and lands on the remembered bank: that is
        // what proves it was remembered rather than merely written.
        $this->settle(['due_date' => '2026-04-09', 'owed' => '80.0000'])->assertSessionHasNoErrors();

        $this->assertSame(
            $other->id,
            Transaction::where('type', 'transfer')->latest('id')->firstOrFail()->account_id
        );
    }

    public function test_a_card_with_no_bank_and_no_answer_still_refuses(): void
    {
        // The one thing a picker cannot fix. Nothing was named and the card names
        // nothing, so there is no transfer to write.
        $this->card->meta()->update(['meta' => ['term_days' => 15, 'statement_day' => 25]]);
        $this->charge('2026-01-01', '120.0000');

        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])
            ->assertSessionHasErrors([
                'due_date' => 'Card [Card] does not name the bank it is paid from, so it cannot be settled.',
            ]);

        $this->assertSame(0, Transaction::whereIn('type', ['payment', 'transfer'])->count());
    }

    public function test_the_named_account_is_refused_for_being_the_wrong_kind_of_account(): void
    {
        // The same refusal the account form gives, from the same check. A brokerage is
        // not somewhere money is paid from, and the message is asserted as that exact
        // string so a second copy of the rule rather than a shared one fails here.
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->charge('2026-01-01', '120.0000');

        $this->settle([
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
            'settlement_account_id' => $broker->id,
        ])->assertSessionHasErrors('settlement_account_id');

        $this->assertSame(
            'A card can only be paid from a cash account, not a security account.',
            session('errors')->getBag('default')->messages()['settlement_account_id'][0]
        );

        $this->assertSame(0, Transaction::whereIn('type', ['payment', 'transfer'])->count());
    }

    public function test_the_named_account_is_refused_for_being_in_another_currency(): void
    {
        // Refused rather than converted, and for the same reason a charge in another
        // currency is not: nothing here converts between them, so the pairing would be
        // quietly miscounted rather than merely awkward.
        $usd = Account::create(['name' => 'New York', 'status' => 'active', 'type' => 'cash', 'ccy' => 'USD']);
        $this->charge('2026-01-01', '120.0000');

        $this->settle([
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
            'settlement_account_id' => $usd->id,
        ])->assertSessionHasErrors('settlement_account_id');

        $this->assertSame(
            'A HKD card cannot be paid from a USD account.',
            session('errors')->getBag('default')->messages()['settlement_account_id'][0]
        );
    }

    public function test_a_card_naming_itself_is_refused_as_the_wrong_kind_of_account(): void
    {
        // Wanted a check here for a card paid from itself -- a transfer with no other
        // side -- and there does not need to be one. A settlement target is always a
        // cash account, and neither a card nor a brokerage is one, so the type check
        // above refuses a self-named target before anything else gets a chance to. The
        // only account that could name itself is a cash account, and AccountMetaData
        // prohibits the field for one.
        $this->charge('2026-01-01', '120.0000');

        $this->settle([
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
            'settlement_account_id' => $this->card->id,
        ])->assertSessionHasErrors([
            'settlement_account_id' => 'A card can only be paid from a cash account, not a card account.',
        ]);

        $this->assertSame(0, Transaction::whereIn('type', ['payment', 'transfer'])->count());
    }

    public function test_the_named_account_must_exist(): void
    {
        $this->charge('2026-01-01', '120.0000');

        $this->settle([
            'due_date' => self::PERIOD,
            'owed' => '120.0000',
            'settlement_account_id' => 9999,
        ])->assertSessionHasErrors('settlement_account_id');
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

        // What the panel would list, which is the periods still owing -- read through
        // the page rather than off the class, because the page is what filters and a
        // test of a helper asserts only that the helper is the helper.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('statements.0.periods', 1)
            ->where('statements.0.periods.0.due_date', '2026-03-12')
            ->where('statements.0.periods.0.owed', '80.0000')
        );
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

        // The pairing is written by settle() straight to the bag, and a payload's claim
        // to one is dropped -- so a client naming somebody else's transaction gets a row
        // that points at nothing, rather than one whose delete takes that row with it.
        $this->post('/transactions', $this->chargePayload([
            'date' => '2026-01-02',
            'meta_data' => ['paired_transaction_id' => $first->id],
        ]))->assertSessionHasNoErrors();

        $forged = Transaction::latest('id')->firstOrFail();

        $this->assertArrayNotHasKey(
            'paired_transaction_id',
            $forged->meta_data->getArrayCopy(),
            'A client-supplied pair reached the bag.'
        );
        $this->assertSame(self::PERIOD, $forged->meta_data['due_date']);

        // Nor on an edit, which is where the form sends a pairing legitimately.
        $this->put("/transactions/{$forged->id}", $this->chargePayload([
            'date' => '2026-01-02',
            'meta_data' => ['paired_transaction_id' => $first->id],
        ]))->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('paired_transaction_id', $forged->fresh()->meta_data->getArrayCopy());
    }

    public function test_the_bank_half_of_a_settlement_keeps_the_payments_amount(): void
    {
        // The transfer has no due date, so no statement guard sees it. Changing its
        // amount would have the bank say one figure left and the card say another
        // arrived, with both balances reading as plausible.
        $this->charge('2026-01-01', '120.0000');
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $transfer = Transaction::where('type', 'transfer')->firstOrFail();

        $this->put("/transactions/{$transfer->id}", array_merge(
            TransactionData::from($transfer->load('meta', 'account'))->toArray(),
            ['amount' => '100.0000']
        ))->assertSessionHasErrors([
            'amount' => 'This transfer is one half of a card settlement, so its amount cannot be changed '
                .'on its own. Delete the settlement and settle the statement again.',
        ]);

        $this->assertSame('120.0000', $transfer->fresh()->amount);
    }

    public function test_a_row_whose_other_half_is_gone_edits_freely(): void
    {
        // Half of nothing: destroy() already deletes it alone, and there is no second
        // figure for it to disagree with.
        $orphan = Transaction::create([
            'account_id' => $this->bank->id,
            'date' => '2026-02-01',
            'type' => 'transfer',
            'description' => 'Orphan',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);
        $orphan->meta()->create(['meta' => ['paired_transaction_id' => 9999]]);

        $this->put("/transactions/{$orphan->id}", array_merge(
            TransactionData::from($orphan->load('meta', 'account'))->toArray(),
            ['amount' => '100.0000']
        ))->assertSessionHasNoErrors();

        $this->assertSame('100.0000', $orphan->fresh()->amount);
    }

    public function test_editing_either_half_of_a_settlement_keeps_the_pair(): void
    {
        // The edit form round-trips the whole row, bag and link included, and update()
        // replaces the bag. Refusing the link broke every edit of a settlement row;
        // dropping it without restoring would cut the pair, and a delete would then
        // leave half a settlement behind.
        $this->charge('2026-01-01', '120.0000');
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $payment = Transaction::where('type', 'payment')->firstOrFail();
        $transfer = Transaction::where('type', 'transfer')->firstOrFail();

        foreach ([$payment, $transfer] as $row) {
            $sent = $row->fresh()->load('meta', 'account');

            $this->put("/transactions/{$row->id}", array_merge(
                TransactionData::from($sent)->toArray(),
                ['description' => 'Corrected']
            ))->assertSessionHasNoErrors();
        }

        $this->assertSame($transfer->id, (int) $payment->fresh()->meta_data['paired_transaction_id']);
        $this->assertSame($payment->id, (int) $transfer->fresh()->meta_data['paired_transaction_id']);
        $this->assertSame(self::PERIOD, $payment->fresh()->meta_data['due_date']);

        $this->delete("/transactions/{$payment->id}")
            ->assertSessionHas('message', 'Card settlement ['.self::PERIOD.'] deleted in full: 2 transactions');
    }

    public function test_the_pair_is_written_even_though_the_dto_drops_the_field(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        // Dropped from the payload, not from the column. settle() writes the bag
        // itself, which is the only way a field the client may not name can still be
        // recorded.
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
