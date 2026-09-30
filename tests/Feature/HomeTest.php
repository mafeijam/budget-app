<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Price;
use App\Models\RecurringTransaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * The home page: what is held in cash, and what is owed on the cards.
 */
class HomeTest extends TestCase
{
    use BuildsACard, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
    }

    public function test_it_shows_each_cash_account_with_its_balance(): void
    {
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-01-01',
            'type' => 'deposit',
            'description' => 'Salary',
            'amount' => '30000.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('index')
            ->has('cash', 1)
            ->where('cash.0.name', 'Bank')
            ->where('cash.0.balance', '30000.0000')
        );
    }

    public function test_a_closed_cash_account_shows_only_while_it_holds_money(): void
    {
        // Hidden with money in it, the total would be a figure nobody can account for.
        Account::create(['name' => 'Old empty', 'status' => 'inactive', 'type' => 'cash', 'ccy' => 'HKD']);
        $holding = Account::create(['name' => 'Old holding', 'status' => 'inactive', 'type' => 'cash', 'ccy' => 'HKD']);

        $this->post('/transactions', [
            'account_id' => $holding->id,
            'date' => '2026-01-01',
            'type' => 'deposit',
            'description' => 'Left over',
            'amount' => '5.0000',
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('cash', fn ($rows) => $rows->pluck('name')->all() === ['Bank', 'Old holding'])
        );
    }

    public function test_it_lists_the_statements_still_owing_soonest_first(): void
    {
        $this->travelTo(Carbon::parse('2026-02-01 12:00', 'Asia/Hong_Kong'));

        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-02-10', '80.0000');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('statements', 2)
            ->where('statements.0.due_date', self::PERIOD)
            ->where('statements.0.owed', '120.0000')
            ->where('statements.0.days_until_due', 8)
            ->where('statements.0.card.name', 'Card')
            ->where('statements.1.owed', '80.0000')
        );
    }

    public function test_a_settled_statement_is_not_listed(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->settle(['due_date' => self::PERIOD, 'owed' => '120.0000'])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('statements', 0)
        );
    }

    public function test_a_brokerage_is_shown_at_market_value_beside_the_cash_not_in_it(): void
    {
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $this->post('/transactions', [
            'account_id' => $broker->id,
            'date' => '2026-01-05',
            'type' => 'buy',
            'description' => 'Buy 0700.HK',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '400'],
        ])->assertSessionHasNoErrors();

        Price::create(['symbol' => '0700.HK', 'date' => today()->toDateString(), 'close' => '440.0000', 'ccy' => 'HKD', 'source' => 'yahoo']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('cash', fn ($rows) => $rows->pluck('name')->all() === ['Bank'])
            ->has('brokerages', 1)
            ->where('brokerages.0.name', 'Broker')
            ->where('brokerages.0.market_value', '44000.0000')
            ->where('brokerages.0.unrealised', '4000.0000')
            ->where('brokerages.0.open', 1)
            ->where('brokerages.0.unpriced', 0)
        );
    }

    public function test_a_closed_brokerage_holding_nothing_is_not_shown(): void
    {
        Account::create(['name' => 'Old broker', 'status' => 'inactive', 'type' => 'security', 'ccy' => 'HKD']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('brokerages', 0));
    }

    public function test_the_headline_is_the_net_worth_pages_figures_for_today(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 12:00', 'Asia/Hong_Kong'));

        $this->deposit('2026-01-10', '1000.0000');
        $this->deposit('2026-02-10', '500.0000');
        $this->charge('2026-02-12', '200.0000');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('headline.net_worth', '1300.0000')
            ->where('headline.cash', '1500.0000')
            ->where('headline.cards', '-200.0000')
            // Keyed by figure, so every card's note comes from one expression on the
            // client and net worth is not the one that carries it.
            ->where('headline.last_month.net_worth', '1000.0000')
            ->where('headline.change.net_worth', '300.0000')
            ->where('headline.last_month.cash', '1000.0000')
            ->where('headline.change.cash', '500.0000')
            ->where('headline.last_month.cards', '0.0000')
            ->where('headline.change.cards', '-200.0000')
            ->where('headline.change.value', '0.0000')
            ->where('headline.owed', '200.0000')
            // Deferred, so it is not in the first response at all. Asserted here because
            // a `->where('trend', ...)` against a prop that never arrives passes against
            // nothing, which is the same way a broken chart hides.
            ->missing('trend')
        );

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            // January's end, then today's: the history starts at the first transaction.
            ->loadDeferredProps('default', fn (Assert $deferred) => $deferred
                ->where('trend', fn ($points) => $points->pluck('date')->all() === ['2026-01-31', '2026-02-15'])
                ->where('trend.1.cards', '-200.0000')
            )
        );
    }

    public function test_it_is_all_clear_with_nothing_to_deal_with(): void
    {
        $this->deposit('2026-01-10', '1000.0000');

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('attention', []));
    }

    public function test_an_overdue_statement_a_negative_account_and_due_pending_rows_need_attention(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 12:00', 'Asia/Hong_Kong'));

        $this->charge('2026-01-01', '1234.5000');

        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-02-20',
            'type' => 'withdraw',
            'description' => 'Overdrawn',
            'amount' => '50.0000',
            'ccy' => 'HKD',
            'category_id' => $this->category,
        ])->assertSessionHasNoErrors();

        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => '2026-02-25',
            'type' => 'deposit',
            'description' => 'Refund',
            'amount' => '10.0000',
            'ccy' => 'HKD',
            'status' => 'pending',
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('attention.0.level', 'negative')
            ->where('attention.0.message', 'Card: 1,234.50 HKD was due on '.self::PERIOD)
            ->where('attention.0.link.data.filter.due_date', self::PERIOD)
            ->where('attention.1.message', 'Bank is below zero: -50.00 HKD')
            ->where('attention', fn ($items) => $items->contains('message', '1 pending transaction is due and not posted yet'))
        );
    }

    public function test_stale_prices_need_attention_only_while_shares_are_held(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 12:00', 'Asia/Hong_Kong'));
        $this->deposit('2026-01-02', '100000.0000');

        $stale = fn ($items) => $items->contains(fn ($item) => str_starts_with($item['message'], 'Prices'));

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('attention', fn ($items) => ! $stale($items)));

        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->meta()->create(['meta' => ['settlement_account_id' => $this->bank->id]]);

        $this->post('/transactions', [
            'account_id' => $broker->id,
            'date' => '2026-01-05',
            'type' => 'buy',
            'description' => 'Buy 0700.HK',
            'ccy' => 'HKD',
            'meta_data' => ['symbol' => '0700.HK', 'quantity' => '10', 'unit_price' => '400'],
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('attention', fn ($items) => $items->contains('message', 'Prices have never been fetched'))
        );
    }

    public function test_a_yearly_recurring_coming_due_needs_attention_and_a_monthly_one_does_not(): void
    {
        // A year is the one bill worth being told about before it lands. A monthly rule is
        // inside any window worth naming, so listing one would put an item here on every day
        // of the year and say nothing on any of them -- and a section that always has
        // something in it is read as noise, which is what the rest of this list is for.
        $this->travelTo(Carbon::parse('2026-03-01 12:00', 'Asia/Hong_Kong'));
        $this->deposit('2026-01-10', '1000.0000');

        $rule = fn (string $description, string $frequency, string $start) => RecurringTransaction::create([
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'type' => 'deposit',
            'description' => $description,
            'amount' => '480.0000',
            'ccy' => 'HKD',
            'frequency' => $frequency,
            'start_date' => $start,
            'active' => true,
        ]);

        $rule('INSURANCE', 'yearly', '2026-05-20');
        $rule('AUDIT', 'yearly', '2026-09-20');
        $rule('PTCG', 'monthly', '2026-03-05');

        $says = fn (string $text) => fn ($items) => collect($items)
            ->contains(fn (array $item) => str_contains($item['message'], $text));

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('attention', $says('Yearly recurring [INSURANCE], 480.00 HKD, is due on 2026-05-20, in 80 days'))
            // Beyond the window, so named in neither form: not as coming due, and not as
            // overdue either, which is the other half of the same rule's story.
            ->where('attention', fn ($items) => ! $says('AUDIT')($items))
            ->where('attention', fn ($items) => ! $says('PTCG')($items))
        );

        // The link is the recurring page, where the rule is: there is nothing to fix here, and
        // a year is a long way off for a list to sit in.
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('attention', fn ($items) => collect($items)
                ->firstWhere('message', 'Yearly recurring [INSURANCE], 480.00 HKD, is due on 2026-05-20, in 80 days')['link']['path'] === '/recurring')
        );
    }

    public function test_a_yearly_recurring_past_its_date_is_overdue_and_not_also_coming_due(): void
    {
        // The two notices are about opposite ends of the same gap, and a rule whose date has
        // passed is only ever the first. Saying both would have the same bill on the list
        // twice, once as money that should have gone out and once as money about to.
        $this->travelTo(Carbon::parse('2026-03-01 12:00', 'Asia/Hong_Kong'));
        $this->deposit('2026-01-10', '1000.0000');

        RecurringTransaction::create([
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'type' => 'deposit',
            'description' => 'INSURANCE',
            'amount' => '480.0000',
            'ccy' => 'HKD',
            'frequency' => 'yearly',
            'start_date' => '2025-06-01',
            'active' => true,
        ]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('attention', fn ($items) => collect($items)->contains(fn (array $item) => $item['message'] === 'Recurring [INSURANCE] has not been recorded since 2025-06-01'))
            ->where('attention', fn ($items) => ! collect($items)->contains(fn (array $item) => str_contains($item['message'], 'is due on')))
        );
    }

    /**
     * The props a revisit does not have to compute: the forecast's, and the line.
     */
    private const CACHED = ['month', 'nextMonth', 'upcoming', 'upcomingMore', 'attention'];

    /** Read off a page so the next request can be sent as the same browser's would be. */
    private ?string $assetVersion = null;

    public function test_a_revisit_is_answered_from_what_the_browser_already_has(): void
    {
        $this->deposit('2026-01-01', '30000.0000');

        $first = $this->page();
        $keys = array_keys($first['onceProps'] ?? []);

        // Every cached prop went out, under a key of its own to be remembered by. The line
        // is the exception: it is deferred as well as cached, so its value arrives on its
        // own request and all the first response carries is the key it will be known by.
        foreach (self::CACHED as $prop) {
            $this->assertArrayHasKey($prop, $first['props'], "{$prop} was not sent");
        }

        $this->assertArrayNotHasKey('trend', $first['props'], 'the deferred line came back in the first response');

        $this->assertNotEmpty($keys);

        // The second visit says what it already holds, and the server sends none of it back.
        $revisit = $this->page($this->asBrowser($keys));

        foreach (self::CACHED as $prop) {
            $this->assertArrayNotHasKey($prop, $revisit['props'], "{$prop} was computed again");
        }
    }

    public function test_a_write_moves_the_key_so_a_revisit_cannot_be_answered_from_before_it(): void
    {
        $this->deposit('2026-01-01', '30000.0000');

        $keys = array_keys($this->page()['onceProps'] ?? []);

        $this->deposit('2026-01-02', '1000.0000');

        // The keys the browser holds name the mark before the write, so none of them matches
        // the mark now -- which is the whole mechanism. A card settled and then revisited
        // would otherwise still owe what it no longer owes.
        $after = array_keys($this->page($this->asBrowser($keys))['onceProps'] ?? []);

        $this->assertSame(
            [],
            array_intersect($keys, $after),
            'a cached prop kept the key it had before the write, so the browser could be answered from before it'
        );

        $revisit = $this->page($this->asBrowser($keys));

        foreach (self::CACHED as $prop) {
            $this->assertArrayHasKey($prop, $revisit['props'], "{$prop} was not rebuilt after the write");
        }
    }

    public function test_the_money_figures_are_never_cached(): void
    {
        $this->deposit('2026-01-01', '30000.0000');

        $keys = array_keys($this->page()['onceProps'] ?? []);
        $revisit = $this->page($this->asBrowser($keys));

        // A fast page is not owed a stale balance, and a balance is the one figure a finance
        // dashboard cannot be an hour out of date on.
        foreach (['headline', 'cash', 'statements', 'brokerages'] as $prop) {
            $this->assertArrayHasKey($prop, $revisit['props'], "{$prop} was cached");
        }
    }

    public function test_a_deferred_prop_asked_for_by_name_is_always_resolved(): void
    {
        $this->deposit('2026-01-01', '30000.0000');

        $keys = array_keys($this->page()['onceProps'] ?? []);

        // The rescue link in the page reloads the line on demand, and a once prop the client
        // names explicitly is resolved whatever the browser remembers.
        $this->assertArrayHasKey('trend', $this->page($this->asBrowser($keys, ['trend']))['props']);
    }

    /**
     * The page as a browser would receive it: JSON for an Inertia request, and the embedded
     * page for the first visit, which has to render the root template.
     *
     * @param  array<string, string>  $headers
     * @return array{props: array<string, mixed>, onceProps?: array<string, mixed>}
     */
    private function page(array $headers = []): array
    {
        $content = $this->get('/', $headers)->assertOk()->getContent();

        $json = json_decode($content, true);

        if (is_array($json)) {
            $this->assetVersion = $json['version'] ?? null;

            return $json;
        }

        $this->assertSame(
            1,
            preg_match('/data-page="[^"]*"\s+type="application\/json">(\{.*?\})<\/script>/s', $content, $m),
            'no Inertia page in the response'
        );

        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        $this->assetVersion = $page['version'] ?? null;

        return $page;
    }

    /**
     * The headers a browser sends: an Inertia request, remembering the given once props.
     *
     * @param  list<string>  $keys
     * @param  list<string>|null  $only
     * @return array<string, string>
     */
    private function asBrowser(array $keys, ?array $only = null): array
    {
        $headers = [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ];

        // Without it the server answers 409 and the props under test never get built.
        if ($this->assetVersion !== null) {
            $headers['X-Inertia-Version'] = $this->assetVersion;
        }

        if ($keys !== []) {
            $headers['X-Inertia-Except-Once-Props'] = implode(',', $keys);
        }

        if ($only !== null) {
            $headers['X-Inertia-Partial-Component'] = 'index';
            $headers['X-Inertia-Partial-Data'] = implode(',', $only);
        }

        return $headers;
    }

    private function deposit(string $date, string $amount): void
    {
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => $date,
            'type' => 'deposit',
            'description' => 'Salary',
            'amount' => $amount,
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();
    }
}
