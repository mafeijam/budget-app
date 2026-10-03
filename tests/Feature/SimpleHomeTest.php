<?php

namespace Tests\Feature;

use App\Http\Controllers\HomeController;
use App\Models\RecurringTransaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * A phone opens / on the simple page: net worth, what is held and owed, and the month.
 */
class SimpleHomeTest extends TestCase
{
    use BuildsACard, RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const TABLET = 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/604.1';

    private const DESKTOP = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
    }

    public function test_a_phone_gets_the_simple_page_and_a_computer_or_tablet_the_home_page(): void
    {
        $this->get('/', ['User-Agent' => self::PHONE])->assertInertia(fn (Assert $page) => $page->component('simple'));
        $this->get('/', ['User-Agent' => self::TABLET])->assertInertia(fn (Assert $page) => $page->component('index'));
        $this->get('/', ['User-Agent' => self::DESKTOP])->assertInertia(fn (Assert $page) => $page->component('index'));
    }

    public function test_the_choice_on_the_page_wins_over_the_device(): void
    {
        $this->withUnencryptedCookie(HomeController::VIEW_COOKIE, 'full')
            ->get('/', ['User-Agent' => self::PHONE])
            ->assertInertia(fn (Assert $page) => $page->component('index'));

        $this->withUnencryptedCookie(HomeController::VIEW_COOKIE, 'simple')
            ->get('/', ['User-Agent' => self::DESKTOP])
            ->assertInertia(fn (Assert $page) => $page->component('simple'));
    }

    public function test_the_simple_page_shows_what_the_home_page_does(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 12:00', 'Asia/Hong_Kong'));

        $this->deposit('2026-02-01', '30000.0000');
        $this->charge('2026-02-10', '250.0000');

        $home = $this->get('/', ['User-Agent' => self::DESKTOP])->viewData('page')['props'];

        $this->get('/', ['User-Agent' => self::PHONE])->assertInertia(fn (Assert $page) => $page
            ->component('simple')
            ->where('cash', $home['cash'])
            ->where('statements', $home['statements'])
            ->where('brokerages', $home['brokerages'])
            ->where('headline.net_worth', $home['headline']['net_worth'])
            ->where('headline.cash', '30000.0000')
            ->where('headline.cards', $home['headline']['cards'])
            ->where('month.month', '2026-02')
            ->where('nextMonth.month', '2026-03'));
    }

    public function test_needs_attention_on_the_phone_is_the_yearly_bills_coming_due_and_nothing_else(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 12:00', 'Asia/Hong_Kong'));
        $this->deposit('2026-01-10', '1000.0000');

        $rule = fn (string $description, string $frequency, string $start) => RecurringTransaction::create([
            'account_id' => $this->bank->id,
            'category_id' => $this->category,
            'type' => 'withdraw',
            'description' => $description,
            'amount' => '480.0000',
            'ccy' => 'HKD',
            'frequency' => $frequency,
            'start_date' => $start,
            'active' => true,
        ]);

        $rule('INSURANCE', 'yearly', '2026-05-20');
        $rule('AUDIT', 'yearly', '2026-09-20');
        $rule('DOMAIN', 'yearly', '2025-06-01');
        $rule('PTCG', 'monthly', '2026-02-05');

        $home = collect($this->get('/', ['User-Agent' => self::DESKTOP])->viewData('page')['props']['attention']);

        // The home page has the overdue ones too, which the phone leaves for the desk.
        $this->assertTrue($home->contains('message', 'Recurring [DOMAIN] has not been recorded since 2025-06-01'));

        $this->get('/', ['User-Agent' => self::PHONE])->assertInertia(fn (Assert $page) => $page
            ->where('attention', $home->filter(fn (array $item) => str_starts_with($item['message'], 'Yearly recurring'))->values()->all())
            ->has('attention', 1)
            ->where('attention.0.title', 'INSURANCE')
            ->where('attention.0.amount', '-480.0000')
            ->where('attention.0.when', 'in 80 days'));
    }

    public function test_net_worth_carries_the_home_pages_change_since_last_month(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 12:00', 'Asia/Hong_Kong'));

        $this->deposit('2026-01-10', '1000.0000');
        $this->deposit('2026-02-10', '250.0000');

        $home = $this->get('/', ['User-Agent' => self::DESKTOP])->viewData('page')['props']['headline'];

        $this->get('/', ['User-Agent' => self::PHONE])->assertInertia(fn (Assert $page) => $page
            ->where('headline.change.net_worth', $home['change']['net_worth'])
            ->where('headline.change.net_worth', '250.0000')
            ->where('headline.change.cash', $home['change']['cash'])
            ->where('headline.change.cards', $home['change']['cards'])
            ->where('headline.change.value', $home['change']['value'])
            ->where('headline.last_month.net_worth', $home['last_month']['net_worth'])
            ->where('headline.last_month.cash', $home['last_month']['cash'])
            ->where('headline.last_month.cards', $home['last_month']['cards'])
            ->where('headline.last_month.value', $home['last_month']['value']));
    }

    public function test_the_line_under_net_worth_is_fetched_as_the_phone_page(): void
    {
        $this->travelTo(Carbon::parse('2026-02-15 12:00', 'Asia/Hong_Kong'));
        $this->deposit('2026-01-10', '1000.0000');

        $version = $this->get('/', ['User-Agent' => self::PHONE])
            ->assertInertia(fn (Assert $page) => $page->component('simple')->missing('trend'))
            ->viewData('page')['version'];

        // Answered as the home page, Inertia would swap the phone's page out for it.
        $this->get('/', [
            'User-Agent' => self::PHONE,
            Header::INERTIA => 'true',
            Header::VERSION => $version,
            Header::PARTIAL_COMPONENT => 'simple',
            Header::PARTIAL_ONLY => 'trend',
        ])->assertOk()
            ->assertJsonPath('component', 'simple')
            ->assertJsonPath('props.trend.0.net_worth', fn ($worth) => is_string($worth))
            ->assertJsonMissingPath('props.cash');
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
