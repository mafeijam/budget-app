<?php

namespace Tests\Feature;

use App\Http\Controllers\HomeController;
use App\Http\Controllers\TransactionController;
use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * A phone opens /transactions on its own list, as it opens / on the simple home page: the rows
 * newest first, scrolled in a page at a time.
 */
class SimpleTransactionsTest extends TestCase
{
    use BuildsACard, RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const DESKTOP = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-02-15 12:00', 'Asia/Hong_Kong'));
        $this->setUpCard();
    }

    public function test_the_phone_gets_the_simple_list_where_it_gets_the_simple_home_page(): void
    {
        $this->get('/transactions', ['User-Agent' => self::PHONE])
            ->assertInertia(fn (Assert $page) => $page->component('simple-transaction'));
        $this->get('/transactions', ['User-Agent' => self::DESKTOP])
            ->assertInertia(fn (Assert $page) => $page->component('transaction'));

        $this->withUnencryptedCookie(HomeController::VIEW_COOKIE, 'full')
            ->get('/transactions', ['User-Agent' => self::PHONE])
            ->assertInertia(fn (Assert $page) => $page->component('transaction'));
    }

    public function test_each_row_carries_its_direction_and_its_transfers_other_half(): void
    {
        $this->deposit('2026-02-01', 'Salary', '30000');
        $this->charge('2026-02-10', '250.0000');

        $saving = Account::create(['name' => 'Saving', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->post('/transfers', [
            'from_account_id' => $this->bank->id,
            'to_account_id' => $saving->id,
            'date' => '2026-02-12',
            'amount' => '500',
        ])->assertSessionHasNoErrors();

        $out = Transaction::where('account_id', $this->bank->id)->where('type', 'withdraw')->sole();

        $rows = collect($this->get('/transactions', ['User-Agent' => self::PHONE])
            ->assertInertia(fn (Assert $page) => $page
                ->component('simple-transaction')
                ->where('meta.form', 'transaction-form')
                ->where('settleType', 'payment')
                ->has('options.accounts')
                ->where('formEmpty.date', '2026-02-15'))
            ->viewData('page')['props']['data']['data'])->keyBy('description');

        $this->assertSame(1, $rows['Salary']['direction']);
        $this->assertSame(-1, $rows['Cafe']['direction']);
        $this->assertNull($rows['Cafe']['linked']);

        $this->assertSame(-1, $rows['TRANSFER TO SAVING']['direction']);
        $this->assertSame('transfer', $rows['TRANSFER TO SAVING']['linked']['kind']);
        $this->assertSame($out->id, $rows['TRANSFER FROM BANK']['linked']['id']);

        // Newest first, as the list groups them by day in the order they come.
        $this->assertSame(
            ['2026-02-12', '2026-02-12', '2026-02-10', '2026-02-01'],
            $rows->pluck('date')->values()->all(),
        );
    }

    public function test_a_foreign_row_carries_its_hkd_figure_stated_by_the_card_or_estimated(): void
    {
        Price::create(['symbol' => 'USDHKD=X', 'date' => '2026-02-01', 'close' => '7.8000', 'ccy' => 'HKD', 'source' => 'yahoo']);

        $usd = fn (string $description, string $status, array $meta) => $this->charge('2026-02-10', '10.0000', $status, $meta)
            ->forceFill(['description' => $description, 'ccy' => 'USD'])->save();

        $usd('Stated', 'posted', ['card_amount' => '79.5']);
        $usd('Waiting', 'pending', []);
        $this->charge('2026-02-11', '50.0000');

        $rows = collect($this->get('/transactions', ['User-Agent' => self::PHONE])
            ->viewData('page')['props']['data']['data'])->keyBy('description');

        $this->assertSame(['amount' => '79.5000', 'estimate' => false], $rows['Stated']['base']);
        $this->assertSame(['amount' => '78.0000', 'estimate' => true], $rows['Waiting']['base']);
        $this->assertNull($rows['Cafe']['base']);

        // HKD and USD lead the currency filter, then the rest by code: not JPY before USD.
        $this->charge('2026-02-12', '900.0000')->forceFill(['ccy' => 'JPY'])->save();

        $this->get('/transactions', ['User-Agent' => self::PHONE])
            ->assertInertia(fn (Assert $page) => $page->where('filterOptions.currencies', ['HKD', 'USD', 'JPY']));
    }

    public function test_a_row_says_why_it_cannot_be_deleted_so_its_swipe_can(): void
    {
        $this->charge('2026-01-01', '100.0000');
        $this->charge('2026-02-01', '40.0000');
        $this->payment('2026-02-05', '100.0000', self::PERIOD);

        $rows = collect($this->get('/transactions', ['User-Agent' => self::PHONE])
            ->viewData('page')['props']['data']['data'])->keyBy('date');

        $this->assertStringContainsString('has been settled', $rows['2026-01-01']['refusal']);
        $this->assertNull($rows['2026-02-01']['refusal']);
    }

    public function test_the_list_scrolls_in_a_page_at_a_time_and_merges_its_edit_locks(): void
    {
        foreach (range(1, 35) as $day) {
            $this->deposit(Carbon::parse('2026-01-01')->addDays($day)->toDateString(), "Row {$day}", '10');
        }

        $first = $this->get('/transactions', ['User-Agent' => self::PHONE])->viewData('page');

        $this->assertCount(30, $first['props']['data']['data']);
        $this->assertSame(2, $first['scrollProps']['data']['nextPage']);
        $this->assertContains('data.data', $first['mergeProps']);
        $this->assertContains('editLocks', $first['mergeProps']);

        $this->get('/transactions?page=2', [
            'User-Agent' => self::PHONE,
            Header::INERTIA => 'true',
            Header::VERSION => $first['version'],
            Header::PARTIAL_COMPONENT => 'simple-transaction',
            Header::PARTIAL_ONLY => 'data,editLocks',
        ])->assertOk()
            ->assertJsonCount(5, 'props.data.data')
            ->assertJsonPath('props.data.data.0.description', 'Row 5')
            ->assertJsonPath('scrollProps.data.nextPage', null)
            ->assertJsonMissingPath('props.formEmpty');
    }

    public function test_the_filters_are_the_full_lists_and_are_echoed_to_the_page(): void
    {
        $this->deposit('2026-02-01', 'Salary', '30000');
        $this->deposit('2026-02-02', 'Refund', '40');
        $this->charge('2026-02-03', '25.0000', 'pending');

        $this->get('/transactions?filter[description]=sal', ['User-Agent' => self::PHONE])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter.description', 'sal')
                ->has('data.data', 1)
                ->where('data.data.0.description', 'Salary'));

        $this->get("/transactions?filter[account_id]={$this->card->id}&filter[status]=pending&filter[date_from]=2026-02-02", ['User-Agent' => self::PHONE])
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter.status', 'pending')
                ->has('data.data', 1)
                ->where('data.data.0.description', 'Cafe'));

        // Closed accounts too, and No category, as the full list's filter offers them.
        $this->bank->update(['status' => 'inactive']);

        $props = $this->get('/transactions', ['User-Agent' => self::PHONE])->viewData('page')['props'];

        $this->assertContains($this->bank->id, array_column($props['filterOptions']['accounts'], 'value'));
        $this->assertContains(TransactionController::NO_CATEGORY, array_column($props['filterOptions']['categories'], 'value'));
        $this->assertSame([], (array) $props['filter']);
        $this->assertSame(['HKD'], $props['filterOptions']['currencies']);
        $this->assertSame(['2026-03'], $props['filterOptions']['dueMonths']);
        $this->assertContains('charge', $props['filterOptions']['types']);

        // Type, currency and statement month are the full list's filters too.
        $this->get('/transactions?filter[type]=charge&filter[ccy]=HKD&filter[due_month]=2026-03', ['User-Agent' => self::PHONE])
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.data', 1)
                ->where('data.data.0.description', 'Cafe'));
    }

    private function deposit(string $date, string $description, string $amount): void
    {
        $this->post('/transactions', [
            'account_id' => $this->bank->id,
            'date' => $date,
            'type' => 'deposit',
            'description' => $description,
            'amount' => $amount,
            'ccy' => 'HKD',
        ])->assertSessionHasNoErrors();
    }
}
