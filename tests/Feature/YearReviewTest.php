<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

class YearReviewTest extends TestCase
{
    use BuildsACard;
    use RefreshDatabase;

    private int $travel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
        $this->travel = DB::table('categories')->insertGetId(['name' => 'TRAVEL']);
        Carbon::setTestNow('2026-02-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_year_is_its_income_spending_categories_and_net_worth(): void
    {
        $this->row('deposit', '2025-01-05', '10000', null, 'Salary');
        $this->row('deposit', '2025-02-05', '10000', null, 'Salary');
        $this->row('withdraw', '2025-01-10', '3000', $this->category, 'Market');
        $this->row('withdraw', '2025-01-20', '1000', $this->category, 'Market');
        $this->row('withdraw', '2025-02-10', '6000', $this->travel, 'Flight');
        // A charge is spent the day it was made, not when its statement is due in January.
        $this->charge('2025-12-28', '500');

        $year = $this->review()['2025'];

        $this->assertSame('20000.0000', $year['income']);
        $this->assertSame('10500.0000', $year['spending']);
        $this->assertSame('9500.0000', $year['net']);
        $this->assertSame('47.5', $year['savings_rate']);
        // January kept 6,000 and February 4,000; the months with nothing in them kept nothing,
        // and December, with only the charge, is the one that lost.
        $this->assertSame('2025-01', $year['best']['month']);
        $this->assertSame('2025-12', $year['worst']['month']);

        $this->assertSame(['TRAVEL', 'FOOD'], array_column($year['categories'], 'name'));
        $this->assertSame('57.1', $year['categories'][0]['share']);

        // Nothing before it, so it starts from nothing; the card owes what it was charged.
        $this->assertSame('0.0000', $year['net_worth']['start']);
        $this->assertSame('10000.0000', $year['net_worth']['end']);

        $this->assertSame(['Flight', 'Market', 'Market', 'Charge'], array_column($year['purchases'], 'description'));
        $this->assertSame(['description' => 'Market', 'count' => 2, 'amount' => '4000.0000'], $year['facts']['top_merchant']);
        $this->assertSame(4, $year['facts']['spending_days']);
        $this->assertSame(365, $year['facts']['days']);
    }

    public function test_the_year_running_is_put_against_the_same_months_of_the_last(): void
    {
        $this->row('withdraw', '2025-01-10', '1000', $this->category, 'Market');
        $this->row('withdraw', '2025-02-10', '1000', $this->category, 'Market');
        // After the months 2026 has had: not in what it is compared with.
        $this->row('withdraw', '2025-06-10', '50000', $this->category, 'Market');
        $this->row('withdraw', '2026-01-10', '3000', $this->category, 'Market');

        $review = $this->review();
        $now = $review['2026'];

        $this->assertTrue($now['partial']);
        $this->assertSame('2026-02-15', $now['to']);
        $this->assertSame(['label' => 'Jan–Feb 2025', 'income' => '0.0000', 'spending' => '2000.0000', 'savings_rate' => null], $now['compare']);
        $this->assertSame('2000.0000', $now['categories'][0]['previous']);

        // A year that is over is against the whole of the one before, and the first has none.
        $this->assertNull($review['2025']['compare']);
    }

    public function test_the_years_come_newest_first_and_an_empty_ledger_has_none(): void
    {
        $this->get('/review')->assertInertia(fn (Assert $page) => $page->component('review')->where('years', []));

        // Written as the recurring rules' run writes, not through the app, so the page's mark
        // does not move: the review read a moment ago must not be the one served. A ledger
        // begun in June starts its review at the first whole year.
        $this->row('deposit', '2024-06-01', '100', null, 'Gift');

        $this->assertSame(['2026', '2025'], array_map('strval', array_keys($this->review())));

        // Begun in January, the first year is a whole one and is kept.
        $this->row('deposit', '2024-01-15', '100', null, 'Gift');

        $this->assertSame(['2026', '2025', '2024'], array_map('strval', array_keys($this->review())));
    }

    /** @return array<string, array<string, mixed>> */
    private function review(): array
    {
        $years = null;

        $this->get('/review')->assertInertia(function (Assert $page) use (&$years) {
            $years = $page->toArray()['props']['years'];

            return $page->component('review');
        });

        return $years;
    }

    private function row(string $type, string $date, string $amount, ?int $category, string $description): void
    {
        Transaction::create([
            'account_id' => $this->bank->id,
            'category_id' => $category,
            'date' => $date,
            'type' => $type,
            'description' => $description,
            'amount' => $amount,
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);
    }

    private function charge(string $date, string $amount): void
    {
        $this->post('/transactions', [
            'account_id' => $this->card->id,
            'category_id' => $this->category,
            'date' => $date,
            'type' => 'charge',
            'description' => 'Charge',
            'amount' => $amount,
            'ccy' => 'HKD',
            'status' => 'posted',
            'meta_data' => [],
        ])->assertSessionHasNoErrors();
    }
}
