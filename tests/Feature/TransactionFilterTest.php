<?php

namespace Tests\Feature;

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

    public function test_the_date_range_is_inclusive_at_both_ends(): void
    {
        $this->assertListed(
            ['filter' => ['date_from' => '2026-01-20', 'date_to' => '2026-02-01']],
            ['Rent', 'Books']
        );
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
            ->where('filterOptions.types', ['withdraw', 'charge', 'payment', 'buy', 'sell', 'deposit'])
        );
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
