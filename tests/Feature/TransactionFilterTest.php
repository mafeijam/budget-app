<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'type' => 'expense',
            'description' => 'Rent',
            'amount' => '9000.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-02-15',
            'type' => 'income',
            'description' => 'Salary',
            'amount' => '30000.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();
    }

    public function test_it_filters_by_account(): void
    {
        $this->assertListed(['filter' => ['account_id' => $this->bank->id]], ['Salary', 'Rent']);
    }

    public function test_it_filters_by_several_types_at_once(): void
    {
        $this->assertListed(['filter' => ['type' => 'income,charge']], ['Salary', 'Books', 'Coffee, tea']);
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
            ->where('filterOptions.types', ['expense', 'income', 'transfer', 'charge', 'payment', 'buy', 'sell', 'dividend'])
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
