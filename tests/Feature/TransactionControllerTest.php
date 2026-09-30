<?php

namespace Tests\Feature;

use App\DTO\TransactionData;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Meta;
use App\Models\Transaction;
use App\Support\CardStatement;
use Carbon\Carbon;
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
            'type' => 'withdraw',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', 'Transaction [withdraw] recorded');

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', [
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'date' => '2026-01-10',
            'type' => 'withdraw',
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
            'meta_data' => [],
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::firstOrFail();

        $this->assertSame('120.0000', $transaction->amount);
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

    public function test_a_sell_whose_fees_exceed_its_proceeds_is_a_field_error_not_a_500(): void
    {
        // The derived amount would be negative. This was an exception out of the DTO's
        // constructor, which a request turned into a 500 with the form's figures lost.
        $this->post('/transactions', array_merge($this->tradePayload(['fees' => '99999']), ['type' => 'sell']))
            ->assertStatus(302)
            ->assertSessionHasErrors('meta_data.fees');

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_dividend_keeps_its_supplied_amount(): void
    {
        $this->broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'category_id' => null,
            'date' => '2026-02-02',
            'type' => 'dividend',
            'description' => 'Dividend',
            'amount' => '312.4400',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => 'NVDA', 'brokerage_account_id' => $this->broker->id],
        ])->assertSessionHasNoErrors();

        $dividend = Transaction::where('type', 'dividend')->firstOrFail();

        $this->assertSame('312.4400', $dividend->amount);
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
            'meta_data' => [],
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

    public function test_index_counts_the_days_to_each_due_date_from_hong_kong_today(): void
    {
        // 16:30 UTC on 8 Feb is already 9 Feb in Hong Kong, the day the statement falls
        // due -- the hours in which a browser's own date would say one day to go.
        $this->travelTo(Carbon::parse('2026-02-08 16:30:00', 'UTC'));

        $this->post('/transactions', $this->chargePayload())->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('statements.0.periods.0.due_date', '2026-02-09')
            ->where('statements.0.periods.0.days_until_due', 0)
        );

        $this->travelTo(Carbon::parse('2026-02-12 12:00:00', 'Asia/Hong_Kong'));

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('statements.0.periods.0.days_until_due', -3)
        );
    }

    /**
     * A closed card still owes, so its periods still show.
     *
     * The account picker filters inactive accounts and the statement panel does not,
     * because they answer different questions: a closed account is a poor choice for
     * a *new* transaction, and a poor choice for nothing at all when there is money
     * owed on it. settle() has never checked status, so filtering the panel hid a
     * debt the user could neither see nor discharge -- the one thing the panel is
     * for.
     */
    public function test_a_closed_card_is_still_shown_what_it_owes(): void
    {
        $this->card->update(['status' => 'inactive']);

        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => [],
        ])->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('statements', 1)
            ->where('statements.0.card.id', $this->card->id)
            ->has('statements.0.periods', 1)
            ->where('statements.0.periods.0.owed', '120.0000')
        );
    }

    public function test_a_card_with_nothing_outstanding_is_not_in_the_panel(): void
    {
        // The other half of the rule. Including every card regardless would fill the
        // panel with settled periods, and a period owing nothing is not worth a row --
        // the payment that closed it is in the transaction list below.
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => [],
        ])->assertSessionHasNoErrors();

        // The card needs a bank before it can be settled at all. update, not create:
        // setUp already gave it its terms, and the bag is one row per model.
        $this->card->meta()->update(['meta' => [
            'term_days' => 15,
            'statement_day' => 25,
            'settlement_account_id' => $this->bank->id,
        ]]);

        $this->post('/accounts/'.$this->card->id.'/settle', [
            'due_date' => '2026-02-09',
            'owed' => '120.0000',
        ])->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('statements', 0)
        );
    }

    public function test_index_names_the_bank_each_card_is_paid_from(): void
    {
        // So the settle dialog can say where the money leaves before the user commits,
        // and preselect the picker with it -- hence the id as well as the name. A card
        // with no bank is absent from the map, which is how the dialog knows to offer a
        // choice rather than print one.
        // update, not create: setUp already gave the card its terms, and the bag is
        // one row per model -- the unique index is on (model_id, model_type).
        $this->card->meta()->update(['meta' => [
            'term_days' => 15,
            'statement_day' => 25,
            'settlement_account_id' => $this->bank->id,
        ]]);

        $lonely = Account::create(['name' => 'Lonely', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $lonely->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('cardBanks', [$this->card->id => ['id' => $this->bank->id, 'name' => 'Bank']])
        );
    }

    public function test_index_offers_the_accounts_a_card_could_be_paid_from(): void
    {
        // The picker behind that choice, and the same list the account form is given:
        // cash accounts only, with the currency in the label so an incompatible one is
        // recognisable before the server refuses it rather than silently missing.
        $this->post('/accounts', [
            'name' => 'Brokerage',
            'status' => 'active',
            'type' => 'security',
            'ccy' => 'HKD',
            'meta_data' => ['settlement_account_id' => $this->bank->id],
        ])->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('settlementOptions', [
                ['label' => 'Bank (HKD)', 'value' => $this->bank->id],
            ])
        );
    }

    public function test_index_sends_no_banks_when_no_card_names_one(): void
    {
        // The card in setUp has terms but no bank, which is the default state of a
        // card a user has just added.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('cardBanks', [])
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
            'meta_data' => [],
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
            'meta_data' => [],
        ])->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('data.data', 1)
            ->where('data.data.0.description', 'Cafe')
            ->where('data.data.0.amount', '120.0000')
            ->where('data.data.0.status', 'posted')
            // The derived date reaches the list through the bag, so the table can
            // show which statement a charge belongs to without a column to join on.
            ->where('data.data.0.meta_data.due_date', '2026-02-09')
        );
    }

    public function test_index_names_the_account_each_row_belongs_to(): void
    {
        // The table holds cash, card and trade rows alike, so a number in the corner
        // tells the reader nothing about whose money a row is. The name is the fact,
        // and it has to come from the row rather than from the picker above: the list
        // is not filtered by the account the form happens to be showing.
        //
        // Two accounts, so a hardcoded name cannot pass for a real lookup.
        $this->post('/transactions', $this->expense([
            'date' => '2026-01-02',
            'description' => 'Lunch',
        ]));

        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Cafe',
            'amount' => '120.0000',
            'ccy' => 'HKD',
            'meta_data' => [],
        ])->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('data.data', 2)
            // The set, not the rows by index: both are written in the same second, so
            // created_at cannot separate them and the order is whatever the sort
            // happens to leave. What matters is that each row is labelled with its own.
            ->where('data.data', fn ($rows) => $rows
                ->pluck('account_name')
                ->sort()
                ->values()
                ->all() === ['Bank', 'Card'])
        );
    }

    public function test_index_still_renders_rows_an_account_edit_has_made_invalid(): void
    {
        // A USD charge on an HKD card carries its card-currency figure. Moving the card
        // to USD afterwards makes that figure one the DTO would refuse on a write -- and
        // when the guards also ran on a read, the whole page answered with a redirect
        // instead of the list.
        $this->post('/transactions', $this->chargePayload([
            'ccy' => 'USD',
            'amount' => '100.0000',
            'meta_data' => ['card_amount' => '780.0000'],
        ]))->assertSessionHasNoErrors();

        $this->card->update(['ccy' => 'USD']);

        $this->get('/transactions')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('data.data', 1)
            ->where('data.data.0.meta_data.card_amount', '780.0000')
            ->where('data.data.0.account_name', 'Card')
            ->where('data.data.0.account_ccy', 'USD')
        );
    }

    public function test_index_shows_a_stored_row_as_stored(): void
    {
        // A charge recorded before its card had terms has no period. Deriving one on
        // the way out would show a statement the panel does not group it under.
        Transaction::create([
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => '2026-01-01',
            'type' => 'charge',
            'description' => 'Bare',
            'amount' => '30.0000',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('data.data.0.description', 'Bare')
            ->where('data.data.0.meta_data', null)
        );
    }

    public function test_index_says_which_way_each_row_moves_its_balance(): void
    {
        // Money out of a bank and a charge on a card both lower the balance; a trade
        // moves none. The same answers movesBalanceOn() gives, since that is the source.
        $this->post('/transactions', $this->expense())->assertSessionHasNoErrors();
        $this->post('/transactions', $this->chargePayload())->assertSessionHasNoErrors();
        $this->post('/transactions', $this->expense(['type' => 'deposit', 'category_id' => null]))
            ->assertSessionHasNoErrors();
        $this->post('/transactions', $this->tradePayload())->assertSessionHasNoErrors();

        $ids = Transaction::orderBy('id')->pluck('id');

        $this->get('/transactions?per_page=10')->assertInertia(fn (Assert $page) => $page
            ->where('directions', [$ids[0] => -1, $ids[1] => -1, $ids[2] => 1, $ids[3] => 0])
        );
    }

    public function test_a_supplied_account_name_does_not_rename_the_account(): void
    {
        // The name is derived from the account, so a payload carrying one is inert --
        // and it has to be inert rather than refused, because the edit form round-trips
        // a whole table row and that row now carries the name. Refusing it would break
        // every edit. What must not happen is the label disagreeing with the account.
        $this->post('/transactions', $this->expense([
            'account_name' => 'Somewhere else',
        ]))->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('data.data', fn ($rows) => $rows
                ->pluck('account_name')
                ->all() === ['Bank'])
        );

        $this->assertDatabaseHas('accounts', ['name' => 'Bank']);
    }

    public function test_index_pages_the_list(): void
    {
        foreach (range(1, 11) as $n) {
            $this->post('/transactions', $this->expense([
                'date' => sprintf('2026-01-%02d', $n),
                'description' => "Lunch {$n}",
            ]));
        }

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('data.data', 10)
            ->where('data.meta.total', 11)
            ->where('data.meta.per_page', 10)
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

    public function test_index_lists_by_the_day_the_money_moved_newest_first(): void
    {
        // Entered out of order: the late entry belongs among its own week's rows, not at
        // the top because it was typed in last.
        $this->post('/transactions', $this->expense(['date' => '2026-03-01', 'description' => 'March']));
        $this->post('/transactions', $this->expense(['date' => '2026-01-01', 'description' => 'January']));
        $this->post('/transactions', $this->expense(['date' => '2026-02-01', 'description' => 'February']));

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('data.data', fn ($rows) => $rows->pluck('description')->all() === ['March', 'February', 'January'])
            ->where('params.sort', 'date')
            ->where('params.dir', 'desc')
            ->where('meta.sort', ['by' => 'date', 'dir' => 'desc'])
        );
    }

    public function test_rows_sharing_a_date_keep_one_order_across_pages(): void
    {
        // Six rows on one day over pages of five: every row on exactly one page.
        foreach (range(1, 6) as $n) {
            $this->post('/transactions', $this->expense(['description' => "Row {$n}"]))->assertSessionHasNoErrors();
        }

        $seen = collect([1, 2])->flatMap(fn (int $page) => $this->get("/transactions?page={$page}")
            ->viewData('page')['props']['data']['data'])
            ->pluck('description');

        $this->assertSame(['Row 6', 'Row 5', 'Row 4', 'Row 3', 'Row 2', 'Row 1'], $seen->all());
    }

    public function test_an_unknown_sort_falls_back_to_the_date(): void
    {
        $this->post('/transactions', $this->expense(['date' => '2026-01-01', 'description' => 'First']));
        $this->post('/transactions', $this->expense(['date' => '2026-03-01', 'description' => 'Third']));

        $this->get('/transactions?sort=account_id&dir=sideways')->assertInertia(fn (Assert $page) => $page
            ->where('data.data.0.description', 'Third')
            ->where('params.sort', 'date')
            ->where('params.dir', 'desc')
        );
    }

    public function test_index_seeds_the_form_with_todays_date(): void
    {
        // A new transaction is almost always dated today, and an empty calendar is a
        // click the user makes every time. Seeded through formEmpty rather than a
        // watcher, because useWatchTarget() replaces formEmpty with the row on an
        // edit -- a watcher would have to tell those apart, and this cannot.
        //
        // today() and not a JS date, so the figure is the Asia/Hong_Kong the rest of
        // the app formats with.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('formEmpty.date', today()->toDateString())
        );
    }

    public function test_index_seeds_the_form_as_posted(): void
    {
        // What the DTO defaults an absent status to, so the picker shows the value the
        // save will store rather than a blank.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('formEmpty.status', TransactionStatus::Posted->value)
        );
    }

    public function test_the_seeded_date_does_not_survive_into_an_edit(): void
    {
        // The other half, and the reason for seeding formEmpty rather than assigning
        // form.date: a row carries its own date, and useWatchTarget() defaults the
        // form from the row. A default applied after that would overwrite it.
        $this->post('/transactions', $this->expense([
            'date' => '2026-01-15',
            'description' => 'Lunch',
        ]))->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('formEmpty.date', today()->toDateString())
            ->where('data.data.0.date', '2026-01-15')
        );
    }

    public function test_index_offers_type_options_grouped_by_the_account_type_they_are_legal_for(): void
    {
        // Keyed rather than a flat list, so the form can offer only what the chosen
        // account accepts. A flat list would let the user pick "buy" on a savings
        // account and be refused by the constructor -- correct, but a picker that
        // offers a choice it will reject is worse than one that does not offer it.
        //
        // Derived from TransactionType::accountTypes() rather than written out, and
        // that is what makes it a real check: a case added to the enum and not
        // offered here would fail, which a list of literals would not notice until a
        // user tried to record the type and found it missing from the dropdown.
        $expected = collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $accountType) => [
                $accountType->value => collect(TransactionType::cases())
                    ->filter(fn (TransactionType $type) => $type->isAllowedFor($accountType))
                    ->map(fn (TransactionType $type) => $type->value)
                    ->values()
                    ->all(),
            ])
            ->all();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('typeOptions', $expected)
        );
    }

    public function test_index_offers_a_default_type_for_each_account_type(): void
    {
        // Derived from the enum rather than listed here, so a fourth account type
        // makes this fail rather than quietly arriving with no default and an empty
        // picker nobody would think to report.
        $expected = collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $accountType) => [
                $accountType->value => $accountType->defaultTransactionType()->value,
            ])
            ->all();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('typeDefaults', $expected)
        );
    }

    public function test_every_default_type_is_one_the_account_actually_accepts(): void
    {
        // The invariant the form depends on and cannot check. A default is a
        // convenience; a default the server refuses is a form that pre-fills a value
        // and then rejects the save over a field the user did not touch and cannot
        // see why -- guardAccountType() would be reporting a type pairing the picker
        // itself produced.
        foreach (AccountType::cases() as $accountType) {
            $default = $accountType->defaultTransactionType();

            $this->assertTrue(
                $default->isAllowedFor($accountType),
                "The default for a {$accountType->value} account is '{$default->value}', which "
                .'that account type does not accept. accountTypes() and '
                .'defaultTransactionType() have drifted apart.'
            );
        }
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
        $this->assertArrayNotHasKey('card_amount', $formEmpty);
        $this->assertArrayHasKey('due_date', $formEmpty['meta_data']);
        $this->assertArrayHasKey('card_amount', $formEmpty['meta_data']);
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
    // The dividend's symbol picker
    // ---------------------------------------------------------------------

    public function test_the_form_offers_a_brokerage_the_symbols_it_holds(): void
    {
        // Keyed by account id, so switching accounts in the form offers that account's own
        // holdings. Read off the wire rather than off Positions, because what the browser
        // has is the question: a prop that is right and unsent is a picker offering nothing,
        // and the one user of it is a dividend, which cannot be recorded without a symbol.
        $this->post('/transactions', $this->tradePayload([
            'symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '150.50',
        ]))->assertSessionHasNoErrors();

        $held = $this->get('/transactions')->viewData('page')['props']['heldSymbols'];

        $this->assertSame(['0700.HK'], $held[$this->broker->id]);

        // And nothing for a bank or a card, which hold no shares.
        $this->assertArrayNotHasKey($this->bank->id, $held);
        $this->assertArrayNotHasKey($this->card->id, $held);
    }

    public function test_a_brokerage_is_not_offered_a_symbol_it_has_sold_out_of(): void
    {
        $this->post('/transactions', $this->tradePayload([
            'symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '150.50',
        ]))->assertSessionHasNoErrors();

        $this->post('/transactions', array_merge($this->tradePayload([
            'symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '160.00',
        ]), [
            'type' => 'sell',
            'date' => '2026-02-01',
            'description' => 'Sell 0700.HK',
        ]))->assertSessionHasNoErrors();

        $held = $this->get('/transactions')->viewData('page')['props']['heldSymbols'];

        $this->assertSame([], $held[$this->broker->id]);

        // The picker is not a gate: the symbol is still submittable, since a position sold
        // after the ex-date still pays out and nothing in the DTO checks the list.
        $this->broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'category_id' => null,
            'date' => '2026-03-02',
            'type' => 'dividend',
            'description' => 'Dividend 0700.HK',
            'amount' => '312.4400',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => '0700.HK', 'brokerage_account_id' => $this->broker->id],
        ])->assertSessionHasNoErrors();
    }

    public function test_a_bank_is_offered_the_brokerages_settling_into_it_for_a_dividend(): void
    {
        $this->broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $offered = $this->get('/transactions')->viewData('page')['props']['dividendBrokerages'];

        $this->assertSame([['label' => 'Broker', 'value' => $this->broker->id]], $offered[$this->bank->id]);
        $this->assertArrayNotHasKey($this->card->id, $offered);
    }

    public function test_a_closed_brokerage_is_not_read_for_holdings(): void
    {
        // The form's account picker offers active accounts only, so a list for a closed one
        // is a query and a payload nobody can reach. Asserted by the key being absent rather
        // than empty, which is what the browser distinguishes.
        Account::create(['name' => 'Old broker', 'status' => 'inactive', 'type' => 'security', 'ccy' => 'HKD']);

        $held = $this->get('/transactions')->viewData('page')['props']['heldSymbols'];

        $this->assertSame(
            [$this->broker->id],
            array_keys($held),
            'A closed brokerage was read. It cannot be picked in the form, so the list is '
                .'never shown.'
        );
    }

    // ---------------------------------------------------------------------
    // Description hints
    // ---------------------------------------------------------------------

    public function test_the_description_offers_what_has_been_used_before(): void
    {
        $this->post('/transactions', $this->expense([
            'description' => 'Blue Bottle',
            'date' => '2026-01-05',
        ]))->assertSessionHasNoErrors();

        $this->post('/transactions', $this->expense([
            'description' => 'Blue Bottle',
            'date' => '2026-01-20',
        ]))->assertSessionHasNoErrors();

        $this->post('/transactions', $this->expense([
            'description' => 'Monthly rent',
            'date' => '2026-02-01',
        ]))->assertSessionHasNoErrors();

        // Once each, most recently used first. A list with "Blue Bottle" twice is a list
        // whose first suggestion is the same string twice, and a person typing "blue"
        // sees no more than they would have from one.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('descriptionHints', ['Monthly rent', 'Blue Bottle'])
        );
    }

    public function test_a_description_older_than_the_window_is_not_offered(): void
    {
        // Three years back, against a two-year window. A personal finance app outlives
        // the merchant, and a suggestion nobody would take is in the way of the ones
        // they would.
        $this->post('/transactions', $this->expense([
            'description' => 'Old cafe',
            'date' => today()->subYears(3)->toDateString(),
        ]))->assertSessionHasNoErrors();

        $this->post('/transactions', $this->expense([
            'description' => 'Current cafe',
            'date' => today()->subYear()->toDateString(),
        ]))->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('descriptionHints', ['Current cafe'])
        );
    }

    public function test_a_description_already_in_the_list_is_offered_once(): void
    {
        // The column's collation makes these one description as far as the database is
        // concerned, so GROUP BY collapses them. Two entries differing only in case would
        // be indistinguishable in the suggestion list anyway.
        $this->post('/transactions', $this->expense([
            'description' => 'Coffee',
            'date' => '2026-01-05',
        ]))->assertSessionHasNoErrors();

        $this->post('/transactions', $this->expense([
            'description' => 'coffee',
            'date' => '2026-01-20',
        ]))->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->has('descriptionHints', 1)
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
            'meta_data' => [],
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::with('meta')->firstOrFail();

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
            'meta_data' => [],
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
        // The second write is where a bag goes wrong. Merging would leave a field from
        // the previous save sitting inside the new bag, and the row would read
        // complete while carrying two contradictory values.
        //
        // Asserted by what DISAPPEARS rather than what arrives, because a merge keeps
        // the old key and a replace drops it. That needs a field a client may write,
        // which for a charge leaves only card_amount: due_date is derived,
        // paired_transaction_id is settle()'s alone, and merchant is gone.
        $transaction = $this->storedCharge();

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'ccy' => 'USD',
            'amount' => '100.0000',
            'meta_data' => ['card_amount' => '780.0000'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('780.0000', $transaction->fresh()->meta_data['card_amount']);

        // Back to the card's own currency with no figure stated, which is legal on its
        // own -- so the only thing that can remove the old one is the replace.
        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'meta_data' => [],
        ]))->assertSessionHasNoErrors();

        $fresh = $transaction->fresh();

        $this->assertSame(1, Meta::where('model_type', Transaction::class)->count());
        $this->assertArrayNotHasKey('card_amount', $fresh->meta_data->getArrayCopy());
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

            // The period the edit form actually sends. useWatchTarget seeds the form
            // from a whole table row, and a row's bag carries the period the server
            // derived last time -- so every edit of a charge arrived holding the bill
            // it was already counted in. Deriving only into a gap let that stand, and
            // the charge stayed in a statement its corrected date had left.
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-03-12', $transaction->fresh()->meta_data['due_date']);
    }

    public function test_re_dating_a_charge_takes_it_out_of_the_old_statement(): void
    {
        // The point of the move, and the reason a stale due_date is worse than a wrong
        // number: CardStatement groups on that key, so a charge left behind in the
        // period it has left goes on counting toward what the card owes. Both halves
        // are wrong -- the old period still carries a charge it does not cover, and the
        // new one is short of the one it does -- and the panel is the only place either
        // is visible.
        $transaction = $this->storedCharge();

        $this->put("/transactions/{$transaction->id}", $this->chargePayload([
            'date' => '2026-01-26',
            'meta_data' => ['due_date' => '2026-02-09'],
        ]))->assertSessionHasNoErrors();

        $periods = CardStatement::forAccount($this->card);

        $this->assertFalse(
            $periods->contains(fn (CardStatement $period) => $period->dueDate === '2026-02-09'),
            'The statement the charge left still counts it.'
        );

        $this->assertSame('120.0000', $periods->firstWhere('dueDate', '2026-03-12')?->owed());
    }

    public function test_update_deletes_the_bag_when_the_new_type_has_none(): void
    {
        // The only way a transaction's bag goes away, and it took two rules to
        // establish that. deriveDueDate() fills in a due date for anything with card
        // terms; and a charge is not a type anything else can be. So a charge on a
        // card with terms always has a bag, and the only reachable empty bag is a row
        // changed to a type that has none -- a charge reclassified as a cash expense,
        // which is below.
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
        $this->assertSame('2026-02-09', $transaction->fresh()->meta_data['due_date']);
    }

    public function test_index_names_the_other_half_of_a_card_settlement(): void
    {
        // The delete confirmation cannot invent this: the counterpart is a row of its
        // own, on the other account, and is as likely to be on another page of the list
        // as on this one. So the page carries it, keyed by the row that would take it
        // along, and the dialog says what the user is about to lose.
        $this->card->meta()->update(['meta' => [
            'term_days' => 15,
            'statement_day' => 25,
            'settlement_account_id' => $this->bank->id,
        ]]);

        $this->post('/transactions', $this->chargePayload())->assertSessionHasNoErrors();
        $this->post("/accounts/{$this->card->id}/settle", [
            'due_date' => '2026-02-09',
            'owed' => '120.0000',
        ])->assertSessionHasNoErrors();

        $payment = Transaction::where('type', 'payment')->firstOrFail();
        $transfer = Transaction::where('type', 'withdraw')->firstOrFail();

        // Payments asked for by type: the list hides a card payment otherwise.
        $this->get('/transactions?filter[type]=charge,payment,withdraw')->assertInertia(fn (Assert $page) => $page
            // The whole map in one assertion. A prop of this shape arrives as a
            // Collection rather than a plain array, and comparing it with == would
            // fail on that rather than on the data -- hence all(). Counting the
            // entries as well is free, and it is the assertion that matters: the
            // charge in the list is a row like any other and must not be here.
            ->where('linked', fn ($linked) => $linked->all() == [
                $payment->id => [
                    'id' => $transfer->id,
                    'description' => 'Card payment [Card]',
                    'date' => '2026-02-09',
                    'amount' => '120.0000',
                    'ccy' => 'HKD',
                    'account_name' => 'Bank',
                    'due_date' => null,
                    'kind' => 'settlement',
                ],
                $transfer->id => [
                    'id' => $payment->id,
                    'description' => 'Statement 2026-02-09',
                    'date' => '2026-02-09',
                    'amount' => '120.0000',
                    'ccy' => 'HKD',
                    'account_name' => 'Card',
                    'due_date' => '2026-02-09',
                    'kind' => 'settlement',
                ],
            ])
        );
    }

    public function test_index_sends_no_linked_rows_when_no_page_row_is_paired(): void
    {
        $this->post('/transactions', $this->expense())->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('linked', [])
        );
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
            ->assertSessionHas('message', 'Transaction [withdraw] deleted');

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

    /**
     * Pay off one statement period, the way the panel's settle button does.
     *
     * The card is given a bank first, since settle() refuses a card that does not name
     * one -- and it is given its terms again because the meta bag is one row per model,
     * so writing the bank into it without them would leave the card with no statement
     * day and every period below it unreadable.
     */
    private function settleTheStatementDue(string $dueDate, string $owed): void
    {
        $this->card->meta()->update(['meta' => [
            'term_days' => 15,
            'statement_day' => 25,
            'settlement_account_id' => $this->bank->id,
        ]]);

        $this->post("/accounts/{$this->card->id}/settle", [
            'due_date' => $dueDate,
            'owed' => $owed,
        ])->assertSessionHasNoErrors();
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
            'type' => 'withdraw',
            'description' => 'Lunch',
            'amount' => '42.5000',
            'ccy' => 'HKD',
        ], $overrides);
    }
}
