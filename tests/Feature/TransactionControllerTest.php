<?php

namespace Tests\Feature;

use App\DTO\TransactionData;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Meta;
use App\Models\Transaction;
use App\Support\CardStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Writing a transaction, and the three shapes the one table holds.
 *
 * The table has been empty throughout the project's life: store() returned its own
 * validation rules as the response and nothing was ever inserted. So nothing about
 * persistence is covered, and everything that only shows up once a row exists --
 * the foreign keys, the derived values surviving the round trip, the bag landing
 * beside the columns -- starts here.
 */
class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $card;

    private Account $broker;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->card = Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $this->broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->card->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);

        $this->category = DB::table('categories')->insertGetId(['name' => 'FOOD']);
    }

    // ---------------------------------------------------------------------
    // Storing
    // ---------------------------------------------------------------------

    public function test_a_cash_expense_is_recorded(): void
    {
        $response = $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'date' => '2026-01-10',
            'type' => 'expense',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', 'Transaction [expense] recorded');

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', [
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'date' => '2026-01-10',
            'type' => 'expense',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
        ]);
    }

    public function test_a_cash_expense_records_no_meta_row(): void
    {
        // A bag is only written when it holds something. An empty one would be a
        // row reading "{}" in the account list, for no information.
        $this->post('/transactions', $this->expense());

        $this->assertDatabaseCount('meta', 1); // the card's own terms, from setUp
        $this->assertDatabaseMissing('meta', ['model_type' => Transaction::class]);
    }

    public function test_a_card_charge_keeps_its_derived_due_date_in_the_bag(): void
    {
        // The whole point of moving due_date off the column and into the bag. The
        // value is derived by the constructor from the card's statement day and
        // term, so nothing in the payload supplies it, and it has to survive the
        // round trip as JSON rather than as a column the schema no longer has.
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => ['merchant' => 'Cafe'],
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::firstOrFail();

        $this->assertSame('120.0000', $transaction->amount);
        $this->assertSame('Cafe', $transaction->meta_data['merchant']);
        // Closing on the 25th with a 15-day term, so a charge on 1 Jan is payable
        // on 9 Feb. The same figure TransactionDataTest derives.
        $this->assertSame('2026-02-09', $transaction->meta_data['due_date']);

        $this->assertArrayNotHasKey(
            'due_date',
            $transaction->getAttributes(),
            'due_date is back on the row rather than in the bag.'
        );
    }

    public function test_a_charge_sent_with_no_bag_still_gets_a_due_date(): void
    {
        // meta_data is required for a trade and optional for everything else, so a
        // charge with no bag is a valid payload. TransactionData creates the bag in
        // that case; this checks the controller persists what it made, rather than
        // writing a null bag or skipping the row.
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-02-09', Transaction::firstOrFail()->meta_data['due_date']);
    }

    public function test_a_trade_stores_the_derived_amount_not_the_supplied_one(): void
    {
        $this->post('/transactions', [
            'account_id' => $this->broker->id,
            'category_id' => null,
            'date' => '2026-01-05',
            'type' => 'buy',
            'description' => 'Buy 0700.HK',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '150.50'],
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::firstOrFail();

        // 100 x 150.50 = 15050, and the bag keeps the inputs it was derived from.
        $this->assertSame('15050.0000', $transaction->amount);
        $this->assertSame('0700.HK', $transaction->meta_data['symbol']);
    }

    public function test_a_dividend_keeps_its_supplied_amount(): void
    {
        $this->post('/transactions', [
            'account_id' => $this->broker->id,
            'category_id' => null,
            'date' => '2026-02-02',
            'type' => 'dividend',
            'description' => 'Dividend',
            'amount' => '312.4400',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();

        $this->assertSame('312.4400', Transaction::firstOrFail()->amount);
    }

    public function test_a_new_row_is_posted_and_stamped_by_the_server(): void
    {
        $this->post('/transactions', $this->expense())->assertSessionHasNoErrors();

        $transaction = Transaction::firstOrFail();

        $this->assertSame('posted', $transaction->status);
        $this->assertNotNull($transaction->created_at);
        $this->assertNotNull($transaction->updated_at);
    }

    public function test_a_client_cannot_name_the_rows_own_id(): void
    {
        // MassAssignmentTest covers the allowlist; this is the same property seen
        // through the endpoint, because an id in a store payload would otherwise
        // let a client choose the primary key of a brand new financial record.
        $this->post('/transactions', $this->expense(['id' => 4242]))->assertSessionHasNoErrors();

        $transaction = Transaction::firstOrFail();

        $this->assertNotSame(4242, $transaction->id);
        $this->assertDatabaseMissing('transactions', ['id' => 4242]);
    }

    public function test_a_rejected_payload_writes_nothing(): void
    {
        // Validation runs before the controller, so a bad payload must not leave a
        // half-written row or an orphan bag behind.
        $response = $this->post('/transactions', $this->expense(['amount' => 'not money']));

        $response->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('meta', 1); // the card's terms only
    }

    public function test_a_type_that_is_wrong_for_the_account_is_rejected(): void
    {
        // The constructor's own check, which cannot be a rule because it needs the
        // account row. It has to reach the form as a field error rather than a 500.
        //
        // A charge on a cash account, rather than the obvious "buy on a bank": a buy
        // also forbids an amount and demands a bag, so that payload fails three
        // rules at once and validation reports those before the constructor is ever
        // reached. A charge needs a category, permits an amount and does not require
        // a bag, so this one passes every rule and is refused by the check under
        // test -- and nothing else.
        $response = $this->post('/transactions', $this->expense(['type' => 'charge']));

        $response->assertSessionHasErrors('type');
        $response->assertSessionHasErrors(['type' => 'A charge cannot be recorded on a cash account.']);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_failure_inside_the_write_is_reported_and_rolled_back(): void
    {
        // The contract AccountErrorReportingTest pins for accounts, applied here
        // because store() is a multi-write: the row, then the bag. A failure
        // between them must leave neither.
        //
        // Provoked from a model event rather than by a malformed payload, and
        // deliberately: every rule in TransactionData mirrors its column, so there
        // is no payload that passes validation and is then refused by the database.
        // That is a good property to have and it means the catch path has to be
        // reached some other way. save() runs inside the controller's try, so a
        // model event is genuinely inside it rather than a simulation of it.
        //
        // \RuntimeException fully qualified because this file is namespaced and a
        // bare RuntimeException would resolve to Tests\Feature\RuntimeException --
        // whose absence is an Error, not an Exception, so it would miss the
        // controller's catch and 500. Which is the right behaviour: an Error is a
        // bug in the code, not a database failure, and should not be dressed up as
        // a rolled-back write.
        Exceptions::fake();

        Transaction::creating(fn () => throw new \RuntimeException('simulated write failure'));

        $response = $this->post('/transactions', $this->expense());
        $response->assertSessionHas('message', 'error db...');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('meta', 1); // the card's terms only

        // The cause is reported, not discarded -- otherwise every failure looks
        // alike from the outside and support has nothing to go on.
        Exceptions::assertReported(\RuntimeException::class);
    }

    // ---------------------------------------------------------------------
    // The options the form is built from
    // ---------------------------------------------------------------------

    public function test_index_reports_what_each_card_still_owes(): void
    {
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => ['merchant' => 'Cafe'],
        ])->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('statements', 1)
            ->where('statements.0.card.id', $this->card->id)
            ->where('statements.0.card.name', 'Card')
            ->where('statements.0.card.ccy', 'HKD')
            ->has('statements.0.periods', 1)
            // Derived on the way in, so the panel and the row below it agree.
            ->where('statements.0.periods.0.due_date', '2026-02-09')
            ->where('statements.0.periods.0.charge_count', 1)
            ->where('statements.0.periods.0.charged', '120.0000')
            ->where('statements.0.periods.0.paid', '0.0000')
            ->where('statements.0.periods.0.owed', '120.0000')
            ->where('statements.0.periods.0.pending_count', 0)
        );
    }

    public function test_index_omits_a_card_whose_only_period_is_settled(): void
    {
        // A heading for a card owing nothing is noise, and a card whose period has
        // been paid off would otherwise sit on the page looking like it needs action.
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => ['merchant' => 'Cafe'],
        ])->assertSessionHasNoErrors();

        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => null,
            'date' => '2026-02-01',
            'type' => 'payment',
            'description' => 'Payment',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => ['due_date' => '2026-02-09'],
        ])->assertSessionHasNoErrors();

        // The period is still there, and still in the class's own answer -- the page
        // filters it out, it is not gone.
        $this->assertTrue(CardStatement::forAccount($this->card)->sole()->isSettled());

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('statements', [])
        );
    }

    public function test_index_does_not_list_a_non_card_as_a_statement_group(): void
    {
        // The cash and securities accounts in setUp have no card terms, and a
        // brokerage's trades must not appear as though a bank were owed something.
        $this->post('/transactions', $this->expense())->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('statements', [])
        );
    }

    public function test_index_renders_the_transaction_inertia_page(): void
    {
        $response = $this->get('/transactions');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('transaction')
            ->has('formEmpty')
            ->has('meta')
            ->has('options')
            ->has('data')
            ->has('params')
            ->has('statements')
            ->where('meta.form', 'transaction-form')
            ->where('meta.path', '/transactions')
        );
    }

    public function test_index_lists_the_stored_transactions_with_their_bags(): void
    {
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => ['merchant' => 'Cafe'],
        ])->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('data.data', 1)
            ->where('data.data.0.description', 'Cafe')
            ->where('data.data.0.amount', '120.0000')
            ->where('data.data.0.status', 'posted')
            // The derived date reaches the list through the bag, so the table can
            // show which statement a charge belongs to without a column to join on.
            ->where('data.data.0.meta_data.merchant', 'Cafe')
            ->where('data.data.0.meta_data.due_date', '2026-02-09')
        );
    }

    public function test_index_pages_the_list(): void
    {
        foreach (range(1, 6) as $n) {
            $this->post('/transactions', $this->expense([
                'date' => sprintf('2026-01-%02d', $n),
                'description' => "Lunch {$n}",
            ]));
        }

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('data.data', 5)
            ->where('data.meta.total', 6)
            ->where('data.meta.per_page', 5)
        );
    }

    public function test_index_sorts_by_the_requested_column(): void
    {
        $this->post('/transactions', $this->expense(['date' => '2026-01-01', 'description' => 'First']));
        $this->post('/transactions', $this->expense(['date' => '2026-03-01', 'description' => 'Third']));

        $this->get('/transactions?sort=date&dir=asc')->assertInertia(fn (Assert $page) => $page
            ->where('data.data.0.description', 'First')
            ->where('data.data.1.description', 'Third')
            ->where('params.sort', 'date')
        );
    }

    public function test_index_offers_type_options_grouped_by_the_account_type_they_are_legal_for(): void
    {
        // Keyed rather than a flat list, so the form can offer only what the chosen
        // account accepts. A flat list would let the user pick "buy" on a savings
        // account and be refused by the constructor -- correct, but a picker that
        // offers a choice it will reject is worse than one that does not offer it.
        //
        // Derived from TransactionType::accountTypes() so the two cannot disagree.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('typeOptions', [
                'cash' => ['expense', 'income'],
                'card' => ['charge', 'payment'],
                'security' => ['buy', 'sell', 'dividend'],
            ])
        );
    }

    public function test_index_offers_every_status_and_currency_the_app_accepts(): void
    {
        // Same reason, same route: derived from the enums so the dropdown cannot
        // fall short of a case the server would accept, nor offer one it would
        // refuse. Asserted against the enums rather than against a literal, so
        // adding a case to either is not a reason to edit this test -- the failure
        // it should produce is the picker going stale, not the list.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('statusOptions', array_column(TransactionStatus::cases(), 'value'))
            ->where('currencyOptions', collect(Currency::cases())
                ->map(fn (Currency $currency) => [
                    'label' => $currency->label(),
                    'value' => $currency->value,
                ])
                ->values()
                ->all()
            )
        );
    }

    public function test_index_sends_a_form_with_the_due_date_in_the_bag_and_not_above_it(): void
    {
        // Read off the wire rather than off the DTO, because the form is built from
        // this payload: a field bound to a key the controller stopped sending binds
        // to undefined and submits nothing, which is only visible in a browser.
        //
        // assertArrayNotHasKey rather than ->where('formEmpty.due_date', null),
        // because a missing key and a present null are different payloads and only
        // one of them is what the move should have produced.
        $formEmpty = $this->get('/transactions')->viewData('page')['props']['formEmpty'];

        $this->assertArrayNotHasKey('due_date', $formEmpty);
        $this->assertArrayNotHasKey('fx_rate', $formEmpty);
        $this->assertArrayHasKey('due_date', $formEmpty['meta_data']);
        $this->assertArrayHasKey('fx_rate', $formEmpty['meta_data']);
    }

    public function test_the_form_offers_an_account_of_every_type(): void
    {
        // Not the paginated table set: a card past the first page must still be
        // selectable, which is why this is its own unbounded query.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('options.accounts', 3)
        );
    }

    public function test_each_offered_account_carries_its_type_and_currency(): void
    {
        // The type is what the form needs in order to offer only the transaction
        // types that are legal on the account the user picked -- the whole reason
        // typeOptions is keyed by account type rather than flat. Without it the
        // browser has to hold the pairing itself, which is the copy that drifts.
        //
        // The currency is here for the same reason and a second purpose: a
        // transaction defaults to its account's currency, which is right almost
        // every time, and getting that default right needs the account's own.
        $options = $this->get('/transactions')->viewData('page')['props']['options']['accounts'];

        $byName = collect($options)->keyBy(fn (array $option) => $option['label']);

        $this->assertSame('cash', $byName['Bank']['type']);
        $this->assertSame('card', $byName['Card']['type']);
        $this->assertSame('security', $byName['Broker']['type']);
        $this->assertSame('HKD', $byName['Bank']['ccy']);

        // Inactive accounts stay out, as before.
        $this->assertCount(3, $options);
    }

    public function test_an_inactive_account_is_not_offered(): void
    {
        Account::create(['name' => 'Closed', 'status' => 'inactive', 'type' => 'cash', 'ccy' => 'HKD']);

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('options.accounts', 3)
        );
    }

    // ---------------------------------------------------------------------
    // Reading one back
    // ---------------------------------------------------------------------

    public function test_a_stored_row_reads_back_through_its_bag(): void
    {
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => ['merchant' => 'Cafe', 'fx_rate' => '7.8'],
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::with('meta')->firstOrFail();

        $this->assertSame('Cafe', $transaction->meta_data['merchant']);
        $this->assertSame('7.8', $transaction->meta_data['fx_rate']);
        $this->assertSame('2026-02-09', $transaction->meta_data['due_date']);
        $this->assertSame($this->card->id, $transaction->account->id);
        $this->assertSame($this->category, $transaction->category->id);
    }

    public function test_a_derived_amount_narrower_than_the_column_is_widened_to_it(): void
    {
        // The column is decimal(12,4) and a client may send fewer places. Storing
        // "42.5" would read back as "42.5000" from MySQL but "42.5" from the DTO,
        // so what is asserted is the stored value, not the submitted one.
        $this->post('/transactions', $this->expense(['amount' => '42.5']))->assertSessionHasNoErrors();

        $this->assertSame('42.5000', Transaction::firstOrFail()->amount);
    }

    public function test_a_bag_written_by_the_controller_can_be_replaced_wholesale(): void
    {
        // The second write, which is where a bag can go wrong: updateOrCreate keyed
        // on the meta row's own id, as AccountController does, so re-saving a
        // transaction does not accumulate a second bag.
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => ['merchant' => 'Cafe'],
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::firstOrFail();

        $this->assertSame(1, Meta::where('model_type', Transaction::class)->count());
        $this->assertSame($transaction->id, $transaction->meta->model_id);
    }

    public function test_the_bag_is_optional_for_every_type_except_a_trade(): void
    {
        // Stated here because it is the asymmetry the settle action depends on:
        // a charge with no bag is a valid payload, a buy with no bag is not.
        $this->post('/transactions', $this->expense())->assertSessionHasNoErrors();

        $this->post('/transactions', [
            'account_id' => $this->broker->id,
            'category_id' => null,
            'date' => '2026-01-05',
            'type' => 'buy',
            'description' => 'Buy',
            'ccy' => 'HKD',
        ])->assertSessionHasErrors('meta_data');

        $this->assertDatabaseCount('transactions', 1);
    }

    // ---------------------------------------------------------------------
    // Updating
    // ---------------------------------------------------------------------

    public function test_update_changes_a_transaction(): void
    {
        $transaction = $this->storedCharge();

        $response = $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'description' => 'Cafe Central',
            'amount' => '135.0000',
        ]));

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', 'Transaction [charge] updated');

        $fresh = $transaction->fresh();

        $this->assertSame('Cafe Central', $fresh->description);
        $this->assertSame('135.0000', $fresh->amount);
    }

    public function test_update_ignores_an_id_in_the_payload(): void
    {
        // The payload's id is a number the client chose, and the row's is not the
        // client's to set. MassAssignmentTest covers the allowlist; this is the
        // endpoint, where the consequence would be a transaction renumbered onto
        // another one.
        $target = $this->storedCharge();
        $transaction = $this->storedCharge();

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'id' => $target->id,
            'description' => 'Renamed',
        ]))->assertSessionHasNoErrors();

        $this->assertNotSame($target->id, $transaction->fresh()->id);
        $this->assertSame($transaction->id, $transaction->fresh()->id);
        $this->assertSame('Cafe', $target->fresh()->description);
    }

    public function test_update_keeps_the_original_created_at(): void
    {
        $transaction = $this->storedCharge();
        $original = $transaction->created_at;

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'created_at' => now()->subYears(5)->toAtomString(),
        ]))->assertSessionHasNoErrors();

        $this->assertTrue(
            $transaction->fresh()->created_at->equalTo($original),
            'created_at was rewritten from the payload.'
        );
    }

    public function test_update_replaces_the_bag_rather_than_accumulating_one(): void
    {
        // The second write is where a bag goes wrong. Merging would leave a merchant
        // from the previous save sitting beside the new one, and the row would read
        // complete while carrying two contradictory values.
        $transaction = $this->storedCharge();

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'meta_data' => ['merchant' => 'Different Shop'],
        ]))->assertSessionHasNoErrors();

        $fresh = $transaction->fresh();

        $this->assertSame(1, Meta::where('model_type', Transaction::class)->count());
        $this->assertSame('Different Shop', $fresh->meta_data['merchant']);
        // Re-derived rather than carried over, so the period is right.
        $this->assertSame('2026-02-09', $fresh->meta_data['due_date']);
    }

    public function test_update_re_derives_the_due_date_when_the_date_moves_period(): void
    {
        // A charge on the 26th falls in the next statement, so a client correcting
        // the date has to move the period with it. due_date is derived, not supplied,
        // so this is the server noticing rather than being told.
        $transaction = $this->storedCharge();

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'date' => '2026-01-26',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-03-12', $transaction->fresh()->meta_data['due_date']);
    }

    public function test_update_deletes_the_bag_when_the_new_type_has_none(): void
    {
        // The only way a transaction's bag goes away, and it took three rules to
        // establish that. deriveDueDate() fills in a due date for anything with card
        // terms; TransactionMetaData requires a merchant for a charge regardless of
        // terms; and a charge is not a type anything else can be. So a charge always
        // has a bag, and the only reachable empty bag is a row changed to a type that
        // has none -- a charge reclassified as a cash expense, which is below.
        //
        // Which matters more than it looks. Left behind, the stale due_date keeps the
        // row in a card statement period, and the statement query groups on exactly
        // that key: the expense would show up in what the card owes, and what the
        // card owes would be a figure no user could account for.
        $transaction = $this->storedCharge();

        $this->assertNotNull($transaction->fresh()->meta);

        $this->put("/transactions/{$transaction->id}", $this->expense([
            'description' => 'Reclassified',
        ]))->assertSessionHasNoErrors();

        $fresh = $transaction->fresh();

        $this->assertNull($fresh->meta);
        $this->assertSame(0, Meta::where('model_type', Transaction::class)->count());
        $this->assertSame('Reclassified', $fresh->description);
        $this->assertSame($this->bank->id, $fresh->account_id);
    }

    public function test_update_re_derives_a_trades_amount_from_the_new_numbers(): void
    {
        $this->post('/transactions', $this->tradePayload([
            'quantity' => '100',
        ]))->assertSessionHasNoErrors();

        $id = Transaction::firstOrFail()->id;
        $this->assertSame('15050.0000', Transaction::firstOrFail()->amount);

        $this->put("/transactions/{$id}", $this->tradePayload([
            'quantity' => '200',
        ]))->assertSessionHasNoErrors();

        // 200 x 150.50, rather than the figure the previous numbers gave.
        $this->assertSame('30100.0000', Transaction::firstOrFail()->amount);
    }

    public function test_update_rejects_a_type_that_is_wrong_for_the_account(): void
    {
        $transaction = $this->storedCharge();

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'account_id' => $this->bank->id,
        ]))->assertSessionHasErrors('type');

        // Untouched, not half-written.
        $this->assertSame('Cafe', $transaction->fresh()->description);
        $this->assertSame($this->card->id, $transaction->fresh()->account_id);
    }

    public function test_a_rejected_update_writes_nothing(): void
    {
        $transaction = $this->storedCharge();

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'amount' => 'not money',
        ]))->assertSessionHasErrors('amount');

        $this->assertSame('120.0000', $transaction->fresh()->amount);
        $this->assertSame('Cafe', $transaction->fresh()->meta_data['merchant']);
    }

    // ---------------------------------------------------------------------
    // Deleting
    // ---------------------------------------------------------------------

    public function test_destroy_removes_the_transaction_and_its_bag(): void
    {
        $transaction = $this->storedCharge();

        $response = $this->delete("/transactions/{$transaction->id}");

        $response->assertStatus(302);
        $response->assertSessionHas('message', 'Transaction [charge] deleted');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0, Meta::where('model_type', Transaction::class)->count());
    }

    public function test_destroy_removes_a_row_that_has_no_bag(): void
    {
        // Nothing to delete but the row, and its absence must not be mistaken for a
        // failure -- a cash expense has no bag to begin with.
        $this->post('/transactions', $this->expense())->assertSessionHasNoErrors();

        $this->delete('/transactions/'.Transaction::firstOrFail()->id)
            ->assertSessionHas('message', 'Transaction [expense] deleted');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_deleting_one_charge_changes_what_its_period_owes(): void
    {
        // Why deleting has to reach into the bag: a charge is one row among several
        // sharing a due_date, so removing it changes what the period owes without
        // touching the others.
        $this->storedCharge();
        $this->storedCharge();

        $this->assertSame('240.0000', CardStatement::forAccount($this->card)->sole()->owed());

        $this->delete('/transactions/'.Transaction::orderBy('id')->value('id'))->assertSessionHasNoErrors();

        $this->assertSame('120.0000', CardStatement::forAccount($this->card)->sole()->owed());
    }

    // ---------------------------------------------------------------------

    private function storedCharge(): Transaction
    {
        $this->post('/transactions', $this->chargePayload())->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
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
            'meta_data' => ['merchant' => 'Cafe'],
        ], $overrides);
    }

    private function tradePayload(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->broker->id,
            'category_id' => null,
            'date' => '2026-01-05',
            'type' => 'buy',
            'description' => 'Buy 0700.HK',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '150.50'],
        ], $overrides ? ['meta_data' => array_merge([
            'symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '150.50',
        ], $overrides)] : []);
    }

    private function expense(array $overrides = []): array
    {
        return array_merge([
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'date' => '2026-01-10',
            'type' => 'expense',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
        ], $overrides);
    }
}
