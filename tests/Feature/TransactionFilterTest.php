<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionController;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * Narrowing the transactions list with filter[...], through spatie/laravel-query-builder.
 *
 * The list only: the statement panel, the delete refusals and the edit locks are about
 * whole cards and whole periods, and a filtered page that reported them for its rows
 * alone would show a card owing less than it does.
 */
class TransactionFilterTest extends TestCase
{
    use BuildsACard, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();

        $this->post('/transactions', $this->chargePayload(['date' => '2026-01-05', 'description' => 'Coffee, tea']));
        $this->post('/transactions', $this->chargePayload([
            'date' => '2026-01-20',
            'description' => 'Books',
            'status' => 'pending',
        ]));
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'date' => '2026-02-01',
            'type' => 'withdraw',
            'description' => 'Rent',
            'amount' => '9000.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-02-15',
            'type' => 'deposit',
            'description' => 'Salary',
            'amount' => '30000.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();
    }

    public function test_it_filters_by_account(): void
    {
        $this->assertListed(['filter' => ['account_id' => $this->bank->id]], ['Salary', 'Rent']);
    }

    public function test_it_filters_by_several_accounts_at_once(): void
    {
        $other = Account::create(['name' => 'Other', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);

        $this->post('/transactions', [
            'account_id' => $other->id,
            'date' => '2026-03-01',
            'type' => 'deposit',
            'description' => 'Refund',
            'amount' => '10.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();

        $this->assertListed(
            ['filter' => ['account_id' => "{$other->id},{$this->card->id}"]],
            ['Refund', 'Books', 'Coffee, tea']
        );
    }

    public function test_it_filters_by_several_categories_at_once(): void
    {
        $travel = DB::table('categories')->insertGetId(['name' => 'TRAVEL']);

        $this->post('/transactions', $this->chargePayload([
            'date' => '2026-01-10',
            'description' => 'Flight',
            'category_id' => $travel,
        ]))->assertSessionHasNoErrors();

        // Salary has no category and is left out; everything else is in one of the two.
        $this->assertListed(
            ['filter' => ['category_id' => "{$this->category},{$travel}"]],
            ['Rent', 'Books', 'Flight', 'Coffee, tea']
        );
    }

    public function test_it_filters_to_the_rows_with_no_category(): void
    {
        $none = TransactionController::NO_CATEGORY;

        // Salary is the only one of the four with no category, and it is income, which says
        // nothing about whether the filter found it: the report's uncategorised block is
        // spending, and the list needs a second filter for that.
        $this->assertListed(['filter' => ['category_id' => $none]], ['Salary']);

        // Asked for beside a category, it is either of them. And'ing a row with no category
        // against FOOD is a request for the empty set, and the list would show nothing for a
        // selection the filter bar is holding two of.
        $this->assertListed(
            ['filter' => ['category_id' => "{$this->category},$none"]],
            ['Salary', 'Rent', 'Books', 'Coffee, tea']
        );
    }

    public function test_no_category_is_offered_to_the_filter_and_not_to_the_forms(): void
    {
        // The two forms choose a category from options.categories, and a row with no category
        // is not a choice: offering it there would write NO_CATEGORY into a category_id, which
        // the database has no column for and which no row would ever match.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('options.filterCategories', fn ($options) => collect($options)->last()['value'] === TransactionController::NO_CATEGORY)
            ->where('options.categories', fn ($options) => ! collect($options)->contains('value', TransactionController::NO_CATEGORY))
        );
    }

    public function test_it_filters_by_the_accounts_type(): void
    {
        // The card's rows, whatever their own type: a charge on the card counts, the
        // bank's expense and income do not.
        $this->assertListed(['filter' => ['account_type' => 'card']], ['Books', 'Coffee, tea']);
        $this->assertListed(['filter' => ['account_type' => 'cash,card']], ['Salary', 'Rent', 'Books', 'Coffee, tea']);
    }

    public function test_each_row_carries_its_accounts_type_and_every_type_is_offered(): void
    {
        $this->get('/transactions?per_page=20')->assertInertia(fn (Assert $page) => $page
            ->where('data.data', fn ($rows) => $rows->pluck('account_type')->all() === ['cash', 'cash', 'card', 'card'])
            ->where('filterOptions.accountTypes', ['cash', 'card', 'security'])
        );
    }

    public function test_it_filters_by_several_types_at_once(): void
    {
        $this->assertListed(['filter' => ['type' => 'deposit,charge']], ['Salary', 'Books', 'Coffee, tea']);
    }

    public function test_it_filters_by_the_currency_the_row_was_made_in(): void
    {
        // A USD charge on the HKD card is found under USD, not under its card's HKD.
        $this->post('/transactions', $this->chargePayload([
            'date' => '2026-01-12',
            'description' => 'Hotel',
            'ccy' => 'USD',
            'amount' => '100.0000',
            'meta_data' => ['card_amount' => '780.0000'],
        ]))->assertSessionHasNoErrors();

        $this->assertListed(['filter' => ['ccy' => 'USD']], ['Hotel']);
        $this->assertListed(['filter' => ['ccy' => 'USD,HKD']], ['Salary', 'Rent', 'Books', 'Hotel', 'Coffee, tea']);
    }

    public function test_it_filters_by_status(): void
    {
        $this->assertListed(['filter' => ['status' => 'pending']], ['Books']);
    }

    public function test_the_description_search_is_one_phrase_not_a_list(): void
    {
        // Split on the comma, "Rent, Books" would be two searches and find both rows.
        // As one phrase it finds neither, and the phrase that is there is found whole.
        $this->assertListed(['filter' => ['description' => 'Rent, Books']], []);
        $this->assertListed(['filter' => ['description' => 'coffee, tea']], ['Coffee, tea']);
        $this->assertListed(['filter' => ['description' => 'book']], ['Books']);
    }

    public function test_it_searches_the_symbol_a_row_carries(): void
    {
        $this->buy0700();

        // Whole, and part of it: a ticker is typed by whoever holds it, and nobody
        // types the lot of it every time.
        $this->assertListed(['filter' => ['symbol' => '0700.HK']], ['Buy 0700.HK']);
        $this->assertListed(['filter' => ['symbol' => '700']], ['Buy 0700.HK']);
    }

    public function test_it_searches_several_symbols_at_once(): void
    {
        $broker = $this->broker();

        $this->buy0700($broker);
        $this->post('/transactions', $this->buyPayload([
            'date' => '2026-03-06',
            'description' => 'Buy 0005.HK',
            'meta_data' => ['symbol' => '0005.HK', 'quantity' => '200', 'unit_price' => '80.00'],
        ], $broker))->assertSessionHasNoErrors();

        // Or, not and: rows carrying both tickers are none, so an and would report that
        // this card has never traded either of them.
        $this->assertListed(
            ['filter' => ['symbol' => '0700.HK,0005.HK']],
            ['Buy 0005.HK', 'Buy 0700.HK']
        );

        // One of the two on its own, which is what the field holds after one is removed.
        $this->assertListed(['filter' => ['symbol' => '0005.HK']], ['Buy 0005.HK']);

        // A ticker nothing carries, beside one that is, still finds the one that is.
        $this->assertListed(
            ['filter' => ['symbol' => '0700.HK,9999.HK']],
            ['Buy 0700.HK']
        );
    }

    public function test_the_symbol_search_finds_only_a_symbol_not_the_row_around_it(): void
    {
        $this->buy0700();

        // Every row in these accounts is in HKD, and every one of them is dated in 2026,
        // and a search that reached past the bag for either would list all of them. It
        // must not: the one key the filter reads is the only one it can see.
        $this->assertListed(['filter' => ['symbol' => 'HKD']], []);
        $this->assertListed(['filter' => ['symbol' => '2026']], []);
    }

    public function test_the_symbol_search_finds_a_row_whose_description_says_something_else(): void
    {
        // The description search usually finds a trade too, since a description is
        // written with its ticker in it. This is the case it cannot: the description was
        // typed as something else, and the symbol is the field that says what was held.
        $broker = $this->broker();

        $this->post('/transactions', $this->buyPayload(['description' => 'Top up'], $broker))
            ->assertSessionHasNoErrors();

        $this->assertListed(['filter' => ['description' => '0700']], []);
        $this->assertListed(['filter' => ['symbol' => '0700.HK']], ['Top up']);
    }

    public function test_a_symbol_filter_holding_nothing_usable_filters_nothing(): void
    {
        $this->buy0700();

        // A field the user cleared, and a list whose only entry is blank. Both mean "not
        // filtering by a symbol", and both must leave the list whole: the branches are
        // built from what is in the value, and a where with no branch in it adds no
        // constraint at all -- which would answer a filter that was asked for with the
        // one answer it looks like it gave.
        foreach (['filter%5Bsymbol%5D=', 'filter%5Bsymbol%5D%5B%5D='] as $query) {
            $this->get("/transactions?{$query}&per_page=20")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('data.data', fn ($rows) => $rows->pluck('description')->all() === ['Buy 0700.HK', 'Salary', 'Rent', 'Books', 'Coffee, tea'])
                );
        }
    }

    public function test_a_blank_among_several_symbols_is_dropped_and_the_rest_still_filter(): void
    {
        $this->buy0700();

        // A picker that has been cleared and retyped in leaves a blank behind it. The
        // blank is not a ticker, and treating it as one -- LIKE '%%', which matches
        // everything -- would make the list the answer to a filter that names a ticker.
        $this->assertListed(
            ['filter' => ['symbol' => '0700.HK,']],
            ['Buy 0700.HK']
        );
    }

    public function test_every_ticker_on_file_is_offered_to_the_symbol_filter(): void
    {
        $broker = $this->broker();

        $this->buy0700($broker);
        $this->post('/transactions', $this->buyPayload([
            'date' => '2026-03-06',
            'description' => 'Buy 0005.HK',
            'meta_data' => ['symbol' => '0005.HK', 'quantity' => '200', 'unit_price' => '80.00'],
        ], $broker))->assertSessionHasNoErrors();

        // A trade years old, and its ticker offered anyway: a name does not go stale the
        // way a merchant's does, and the row it finds is one somebody made and may still
        // be asking about. Distinct, and sorted so the list does not shuffle per load.
        $this->post('/transactions', $this->buyPayload([
            'date' => today()->subYears(5)->toDateString(),
            'description' => 'Buy 9988.HK',
            'meta_data' => ['symbol' => '9988.HK', 'quantity' => '50', 'unit_price' => '400.00'],
        ], $broker))->assertSessionHasNoErrors();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('filterOptions.symbols', ['0005.HK', '0700.HK', '9988.HK'])
        );
    }

    public function test_a_row_without_a_ticker_offers_none(): void
    {
        // A charge carries a bag with a due date in it and no symbol, so reading the key
        // off every bag must not put an empty suggestion in the list.
        $this->buy0700();

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('filterOptions.symbols', ['0700.HK'])
        );
    }

    /** A brokerage to trade on, created on demand so a test that needs one asks for it. */
    private function broker(): Account
    {
        return Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
    }

    /** What the transaction form posts for a buy of 100 0700.HK at 150.50. */
    private function buyPayload(array $overrides = [], ?Account $broker = null): array
    {
        return array_merge([
            'account_id' => ($broker ?? $this->broker())->id,
            'category_id' => null,
            'date' => '2026-03-05',
            'type' => 'buy',
            'description' => 'Buy 0700.HK',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '150.50'],
        ], $overrides);
    }

    /** A recorded buy, for the tests that only care that the row exists. */
    private function buy0700(?Account $broker = null): void
    {
        $this->post('/transactions', $this->buyPayload(overrides: [], broker: $broker))
            ->assertSessionHasNoErrors();
    }

    public function test_the_date_range_is_inclusive_at_both_ends(): void
    {
        $this->assertListed(
            ['filter' => ['date_from' => '2026-01-20', 'date_to' => '2026-02-01']],
            ['Rent', 'Books']
        );
    }

    public function test_the_counted_range_lists_a_charge_by_its_due_date(): void
    {
        // Both January charges fall due on 9 February, so they belong to February's cash
        // flow, which is the month whose link asks for them.
        $this->assertListed(
            ['filter' => ['counted_from' => '2026-02-01', 'counted_to' => '2026-02-10']],
            ['Rent', 'Books', 'Coffee, tea']
        );
        $this->assertListed(['filter' => ['counted_from' => '2026-01-01', 'counted_to' => '2026-01-31']], []);
    }

    public function test_the_month_lists_every_cards_statements_due_in_it(): void
    {
        // Both charges are on the statement due 9 February.
        $this->assertListed(['filter' => ['due_month' => '2026-02']], ['Books', 'Coffee, tea']);
        $this->assertListed(['filter' => ['due_month' => '2026-03']], []);

        // Several at once, as the month picker sends them; a bad one among them is dropped.
        $this->assertListed(['filter' => ['due_month' => '2026-03,2026-02']], ['Books', 'Coffee, tea']);
        $this->assertListed(['filter' => ['due_month' => '2026-02,2026-13']], ['Books', 'Coffee, tea']);

        // Not a month, so no filter rather than an empty list.
        $this->assertListed(
            ['filter' => ['due_month' => '2026-13']],
            ['Salary', 'Rent', 'Books', 'Coffee, tea']
        );
    }

    public function test_the_month_lists_what_is_dated_in_it(): void
    {
        $all = ['Salary', 'Rent', 'Books', 'Coffee, tea'];
        $in = fn (string $months) => collect($this->get('/transactions?filter[month]='.$months)
            ->viewData('page')['props']['data']['data'])->pluck('description')->sort()->values()->all();

        // Cash accounts only: the months together are every bank row and no card charge.
        $offered = $this->get('/transactions')->viewData('page')['props']['filterOptions']['months'];
        $this->assertEqualsCanonicalizing(['Salary', 'Rent'], $in(implode(',', $offered)));

        // Not a month, so no filter rather than an empty list.
        $this->assertEqualsCanonicalizing($all, $in('2026-13'));
    }

    public function test_a_date_that_is_not_a_calendar_day_is_ignored(): void
    {
        // Compared as a string, "2026-1-5" sorts after "2026-01-31" and would drop rows
        // without a word. Ignored, it filters nothing.
        $this->assertListed(
            ['filter' => ['date_from' => '2026-1-5', 'date_to' => '2026-02-30']],
            ['Salary', 'Rent', 'Books', 'Coffee, tea']
        );
    }

    public function test_an_unknown_filter_is_refused_rather_than_ignored(): void
    {
        // Ignored, a mistyped key would show the whole list as though it were filtered.
        $this->get('/transactions?filter[acount_id]=1')->assertStatus(400);
    }

    public function test_the_filter_comes_back_so_the_page_can_keep_it(): void
    {
        // The table sends it again when paging or sorting, from params, and the filter
        // bar seeds itself from the same place.
        $this->get('/transactions?filter[status]=pending')->assertInertia(fn (Assert $page) => $page
            ->where('params.filter', ['status' => 'pending'])
        );
    }

    public function test_it_filters_to_the_charges_a_card_still_owes_for(): void
    {
        // The bank's rows are not charges, and Books is listed although it is pending:
        // the period owes 120.00 whatever is in it, and a pending charge is the panel's
        // business rather than this filter's.
        $this->assertListed(['filter' => ['unpaid' => '1']], ['Books', 'Coffee, tea']);
    }

    public function test_a_settled_period_leaves_nothing_unpaid(): void
    {
        // A period of its own, with nothing pending in it, since settle() refuses a
        // period the issuer has not finished billing.
        $this->post('/transactions', $this->chargePayload([
            'date' => '2026-02-10',
            'description' => 'Flight',
            'amount' => '50.0000',
        ]))->assertSessionHasNoErrors();

        $this->settle(['due_date' => '2026-03-12', 'owed' => '50.0000'])
            ->assertSessionHasNoErrors();

        $this->assertListed(['filter' => ['unpaid' => '1']], ['Books', 'Coffee, tea']);
        // The charge is still a charge, so an empty list is the filter's doing and not
        // the row having gone missing.
        $this->assertListed(['filter' => ['type' => 'charge']], ['Flight', 'Books', 'Coffee, tea']);
    }

    public function test_a_card_that_has_paid_owes_nothing_for_another_cards_period(): void
    {
        $other = Account::create(['name' => 'Other card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $other->meta()->create([
            'meta' => ['term_days' => 15, 'statement_day' => 25, 'settlement_account_id' => $this->bank->id],
        ]);

        // The same day as Coffee, tea, so both cards' charges are filed under
        // 2026-02-09 and the two are told apart by the card alone.
        $this->chargeOn($other, '2026-01-05', '70.0000');

        $this->post("/accounts/{$other->id}/settle", ['due_date' => self::PERIOD, 'owed' => '70.0000'])
            ->assertSessionHasNoErrors();

        $this->assertListed(['filter' => ['unpaid' => '1']], ['Books', 'Coffee, tea']);
        // The charge is there and still a charge; the payment that settled it is a
        // charge's counterpart and is not owed for either.
        $this->assertListed(
            ['filter' => ['account_id' => $other->id, 'type' => 'charge']],
            ['Cafe']
        );
    }

    public function test_a_period_holding_only_a_pending_charge_owes_nothing_yet(): void
    {
        // It is a bill once the charge posts, and until then it totals nothing -- which
        // is why the panel shows no such period and settle() refuses it. Answering from
        // isSettled() says the same thing instead of having its own idea of paid.
        $this->post('/transactions', $this->chargePayload([
            'date' => '2026-02-10',
            'description' => 'Groceries',
            'status' => 'pending',
        ]))->assertSessionHasNoErrors();

        $this->assertListed(['filter' => ['unpaid' => '1']], ['Books', 'Coffee, tea']);
        $this->assertListed(['filter' => ['type' => 'charge']], ['Groceries', 'Books', 'Coffee, tea']);
    }

    public function test_nothing_is_listed_when_every_period_is_settled(): void
    {
        // The pending charge is the one thing stopping the period being settled, and
        // settle() refuses a period with pending rows in it, so it goes. Written the
        // way destroy() does it, bag and row.
        $pending = Transaction::where('description', 'Books')->firstOrFail();
        $pending->meta()->delete();
        $pending->delete();

        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])
            ->assertSessionHasNoErrors();

        // An empty list, and not the whole one: a branch with no periods in it adds no
        // constraint, so the filter would hand back everything and look like it had
        // done nothing.
        $this->assertListed(['filter' => ['unpaid' => '1']], []);
        $this->assertListed(['filter' => ['type' => 'charge']], ['Coffee, tea']);
    }

    public function test_a_value_that_does_not_say_yes_filters_nothing(): void
    {
        $this->assertListed(['filter' => ['unpaid' => '0']], ['Salary', 'Rent', 'Books', 'Coffee, tea']);
    }

    public function test_the_unpaid_filter_comes_back_so_the_page_can_keep_it(): void
    {
        // The toggle seeds itself from the same place the other filters do, so a shared
        // link arrives switched on rather than showing the whole list.
        $this->get('/transactions?filter[unpaid]=1')->assertInertia(fn (Assert $page) => $page
            ->where('params.filter', ['unpaid' => '1'])
        );
    }

    public function test_the_symbol_search_comes_back_so_the_page_can_keep_it(): void
    {
        // The field seeds itself from the same place the other filters do, so a shared
        // link arrives with the phrase still in it.
        $this->get('/transactions?filter[symbol]=0700')->assertInertia(fn (Assert $page) => $page
            ->where('params.filter', ['symbol' => '0700'])
        );
    }

    public function test_the_statement_panel_ignores_the_filter(): void
    {
        // Filtered to the bank, the card still owes what it owes.
        $this->get('/transactions?filter[account_id]='.$this->bank->id)->assertInertia(fn (Assert $page) => $page
            ->where('statements.0.card.id', $this->card->id)
            ->where('statements.0.periods.0.owed', '120.0000')
        );
    }

    public function test_every_account_is_offered_to_filter_by_even_a_closed_one(): void
    {
        $closed = Account::create(['name' => 'Old card', 'status' => 'inactive', 'type' => 'card', 'ccy' => 'HKD']);

        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('filterOptions.accounts', fn ($accounts) => $accounts->pluck('value')->contains($closed->id))
            ->where('filterOptions.types', ['deposit', 'withdraw', 'charge', 'payment', 'buy', 'sell', 'dividend'])
        );
    }

    public function test_it_filters_to_one_statement_by_its_due_date(): void
    {
        // The next period, so a card's other statement is what the filter leaves out.
        $this->post('/transactions', $this->chargePayload(['date' => '2026-01-28', 'description' => 'Later']))
            ->assertSessionHasNoErrors();

        $due = Transaction::where('description', 'Books')->firstOrFail()->meta->meta['due_date'];

        $this->assertListed(
            ['filter' => ['account_id' => $this->card->id, 'due_date' => $due]],
            ['Books', 'Coffee, tea']
        );
    }

    public function test_a_malformed_due_date_filters_nothing(): void
    {
        $this->assertListed(['filter' => ['due_date' => '2026-2-9']], ['Salary', 'Rent', 'Books', 'Coffee, tea']);
    }

    public function test_a_filtered_list_totals_every_matching_row_not_just_the_page(): void
    {
        // Two charges of 120, a withdrawal of 9000 and a deposit of 30000, on pages of one.
        $this->get('/transactions?per_page=5&filter[ccy]=HKD')->assertInertia(fn (Assert $page) => $page
            ->where('totals', [[
                'ccy' => 'HKD',
                'count' => 4,
                'in' => '30000.0000',
                'out' => '9240.0000',
                'net' => '20760.0000',
                'trades' => '0.0000',
            ]])
        );
    }

    public function test_an_unfiltered_list_totals_every_row(): void
    {
        // The same rows as the HKD filter above, since every row here is HKD: the card stays
        // when the last filter goes rather than taking the page's height with it.
        $this->get('/transactions')->assertInertia(fn (Assert $page) => $page
            ->where('totals.0.count', 4)
            ->where('totals.0.net', '20760.0000')
        );
    }

    public function test_a_card_payment_is_hidden_unless_the_filter_asks_for_it(): void
    {
        $this->payment('2026-02-01', '50.0000', '2026-02-09');

        $listed = fn (string $query) => $this->get('/transactions?per_page=20'.$query)
            ->viewData('page')['props']['data']['data'];
        $payments = fn (string $query) => collect($listed($query))->where('type', 'payment')->count();

        $this->assertSame(0, $payments(''));
        $this->assertSame(1, $payments('&filter[type]=payment'));
        $this->assertSame(1, $payments('&filter[account_id]='.$this->card->id));
        $this->assertSame(1, $payments('&filter[account_id]='.$this->card->id.'&filter[due_date]=2026-02-09'));
    }

    public function test_it_filters_to_spending_as_the_cash_flow_report_counts_it(): void
    {
        $this->cardPaymentOnBank($this->payment('2026-02-01', '120.0000', self::PERIOD));

        // The three rows that took money out of the user's own hands. Left out: Salary, which
        // came in, and the bank's own side of settling the card -- a payment is a withdrawal
        // on the bank, so no list of types can tell it from Rent, and the charge it settles is
        // already counted by the report.
        $this->assertListed(['filter' => ['spending' => '1']], ['Rent', 'Books', 'Coffee, tea']);

        // A value that does not say yes is no filter, as with unpaid, so a stale
        // filter[spending]=0 off the address bar does not empty the page.
        $this->assertListed(
            ['filter' => ['spending' => '0']],
            ['Salary', 'Card payment', 'Rent', 'Books', 'Coffee, tea']
        );
    }

    public function test_the_link_out_of_a_cash_flow_block_lists_what_the_block_says(): void
    {
        // An uncategorised withdrawal to sit under the uncategorised block, and a card payment
        // with no category either: if the spending filter let the settlement through, the block
        // would say 500 and the list behind it 620, and nothing on the page would say so.
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-02-02',
            'type' => 'withdraw',
            'description' => 'ATM',
            'amount' => '500.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();

        $this->cardPaymentOnBank($this->payment('2026-02-01', '120.0000', self::PERIOD));

        $february = collect($this->get('/cash-flow')->viewData('page')['props']['report'][0]['months'])
            ->firstWhere('month', '2026-02');
        $uncategorised = collect($february['categories'])->firstWhere('id', null);

        // Rent is under FOOD, and the settlement the card paid is not spending at all.
        $this->assertSame('500.0000', $uncategorised['amount']);

        // The block's own month and its own category, read off the report, put through the
        // filter the cash flow page builds for its link.
        $this->get('/transactions?'.http_build_query([
            'per_page' => 20,
            'filter' => [
                'counted_from' => $february['from'],
                'counted_to' => $february['to'],
                'spending' => '1',
                'category_id' => TransactionController::NO_CATEGORY,
            ],
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('data.data', fn ($rows) => $rows->pluck('description')->all() === ['ATM'])
            ->where('totals.0.count', 1)
            ->where('totals.0.out', $uncategorised['amount'])
        );
    }

    /** The bank withdrawal that pays a card, which is half of a pair and not spending. */
    private function cardPaymentOnBank(Transaction $payment): Transaction
    {
        $withdrawal = Transaction::create([
            'account_id' => $this->bank->id,
            'category_id' => null,
            'date' => '2026-02-05',
            'type' => 'withdraw',
            'description' => 'Card payment',
            'amount' => $payment->amount,
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);

        $withdrawal->meta()->create(['meta' => ['paired_transaction_id' => $payment->id]]);

        return $withdrawal;
    }

    public function test_the_amount_sorts_by_its_signed_figure(): void
    {
        // Salary +30000, then the two charges of -120, newer first on the id tiebreak, then
        // rent at -9000. By magnitude, rent would come second.
        $this->assertListed(['sort' => 'amount', 'dir' => 'desc'], ['Salary', 'Books', 'Coffee, tea', 'Rent']);
    }

    /** The descriptions listed for a query, newest day first. */
    private function assertListed(array $query, array $descriptions): void
    {
        $this->get('/transactions?'.http_build_query($query + ['per_page' => 20]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.data', fn ($rows) => $rows->pluck('description')->all() === $descriptions)
            );
    }
}
