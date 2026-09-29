<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * That the home page's cost follows the points it shows, not the age of the ledger.
 *
 * Both regressions were silent and both were invisible until the ledger held years of
 * statements rather than a season of them, so each is pinned by a count rather than by a
 * figure: a page that renders the same numbers slowly is still a broken page, and a
 * figure-only assertion would pass on the slow version.
 */
class HomeQueryCountTest extends TestCase
{
    use BuildsACard, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCard();
    }

    /**
     * The count the fixes allow. Generous, because the exact number moves with unrelated
     * work; what matters is that it is a small constant rather than a multiple of the
     * number of statements.
     */
    private const BUDGET = 90;

    public function test_a_deep_ledger_does_not_make_the_page_slower(): void
    {
        // 120 settled periods, the shape a decade of statements gives. A page that
        // recomputes a point per month of history, or fetches each period's rows before
        // deciding the period is settled, is over eight hundred queries by here.
        $this->settledPeriods(120);

        $queries = $this->countQueriesForHome();

        $this->assertLessThanOrEqual(
            self::BUDGET,
            $queries,
            "Home ran {$queries} queries against 120 settled statements. A per-statement or "
            .'per-history-point query has crept back in; the page must not cost more as the '
            .'ledger gets older.'
        );
    }

    public function test_a_shallow_ledger_costs_about_the_same_as_a_deep_one(): void
    {
        $shallow = $this->countQueriesForHome();

        $this->settledPeriods(120);

        $deep = $this->countQueriesForHome();

        // The point of the fix: a decade of history must not cost more than a fortnight.
        $this->assertLessThanOrEqual(
            $shallow + 10,
            $deep,
            "Home cost {$shallow} queries on a shallow ledger and {$deep} on a deep one. "
            .'The difference is the ledger being walked rather than summarised.'
        );
    }

    /**
     * A statement closed and paid off, on its own date, so each one is a settled period
     * that the page has every reason to look at and no reason to open.
     */
    private function settledPeriods(int $howMany): void
    {
        $start = Carbon::parse('2017-01-01');

        foreach (range(0, $howMany - 1) as $n) {
            $charge = $start->copy()->addMonthsNoOverflow($n)->day(5);
            $due = $this->card->meta()->first()?->meta;

            $transaction = Transaction::create([
                'account_id' => $this->card->id,
                'category_id' => $this->category,
                'date' => $charge->toDateString(),
                'type' => 'charge',
                'description' => 'Cafe',
                'amount' => '100.0000',
                'ccy' => 'HKD',
                'status' => 'posted',
            ]);

            $period = CardStatementCycle::fromMeta($due)
                ->dueDateFor($charge)
                ->toDateString();

            $transaction->meta()->create(['meta' => ['due_date' => $period]]);

            $payment = Transaction::create([
                'account_id' => $this->card->id,
                'category_id' => null,
                'date' => $period,
                'type' => 'payment',
                'description' => 'Payment',
                'amount' => '100.0000',
                'ccy' => 'HKD',
                'status' => 'posted',
            ]);

            $payment->meta()->create(['meta' => ['due_date' => $period]]);
        }
    }

    private function countQueriesForHome(): int
    {
        $this->travelTo(Carbon::parse('2026-09-29 12:00', 'Asia/Hong_Kong'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/')->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }
}
