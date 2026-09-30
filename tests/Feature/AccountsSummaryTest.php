<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * The accounts page's figures beside each row: the section subtotals, the twelve-month
 * line, and the card's next bill. The subtotals are NetWorth's, so they cannot disagree
 * with the Net worth page.
 */
class AccountsSummaryTest extends TestCase
{
    use BuildsACard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
        Carbon::setTestNow('2026-09-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_page_carries_the_net_worth_figures_a_trend_and_the_next_bill(): void
    {
        Transaction::create([
            'account_id' => $this->bank->id, 'date' => '2026-07-01', 'type' => 'deposit',
            'description' => 'Salary', 'amount' => '1000', 'ccy' => 'HKD', 'status' => 'posted',
        ]);
        $this->charge('2026-08-20', '120');
        $this->charge('2026-09-01', '30');

        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->where('summary.cash', '1000.0000')
            ->where('summary.cards', '-150.0000')
            ->where('summary.net_worth', '850.0000')
            // Twelve month ends and today, oldest first; zero before the first row.
            ->has("trends.{$this->bank->id}", 13)
            ->where("trends.{$this->bank->id}.0", '0.0000')
            ->where("trends.{$this->bank->id}.12", '1000.0000')
            // The earlier bill, due 9 September and so already late, with the next one
            // counted rather than shown.
            ->where("statements.{$this->card->id}.due_date", '2026-09-09')
            ->where("statements.{$this->card->id}.owed", '120.0000')
            ->where("statements.{$this->card->id}.more", 1)
            ->missing("statements.{$this->bank->id}")
        );
    }

    public function test_a_card_with_nothing_owed_has_no_bill(): void
    {
        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->missing("statements.{$this->card->id}")
        );
    }
}
