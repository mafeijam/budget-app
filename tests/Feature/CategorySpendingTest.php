<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsACard;
use Tests\TestCase;

/**
 * The categories page's figures: each category's spending by month off the Cash flow
 * report read by charge date, with spending under no category as its own row.
 */
class CategorySpendingTest extends TestCase
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

    public function test_each_category_carries_its_months_its_total_and_its_average(): void
    {
        $unused = Category::create(['name' => 'DOG']);
        $this->withdraw('2026-09-02', '300', $this->category);
        $this->withdraw('2026-07-02', '60', null);
        // Made in August and due in September: a category counts it when it was bought.
        $this->charge('2026-08-20', '120');
        // Made this month and not due until October, which by due date would be missing.
        $this->charge('2026-09-10', '15');

        $this->get('/categories')->assertInertia(fn (Assert $page) => $page
            ->has('months', 12)
            ->where('spending.total', '495.0000')
            ->where("spending.categories.{$this->category}.total", '435.0000')
            ->where("spending.categories.{$this->category}.months.10", '120.0000')
            ->where("spending.categories.{$this->category}.months.11", '315.0000')
            ->where("spending.categories.{$this->category}.average", '36.2500')
            ->where('window.from', '2025-10-01')
            ->where('window.to', '2026-09-30')
            ->where('spending.categories.0.total', '60.0000')
            ->missing("spending.categories.{$unused->id}")
            ->where("usage.{$this->category}.transactions", 3)
            ->where("usage.{$this->category}.last_date", '2026-09-10')
            ->where("usage.{$unused->id}.transactions", 0)
            ->has('data.data', 2)
        );
    }

    private function withdraw(string $date, string $amount, ?int $category): void
    {
        Transaction::create([
            'account_id' => $this->bank->id, 'category_id' => $category, 'date' => $date,
            'type' => 'withdraw', 'description' => 'Spent', 'amount' => $amount, 'ccy' => 'HKD',
            'status' => 'posted',
        ]);
    }
}
