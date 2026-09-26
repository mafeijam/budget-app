<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Support\CardStatementCycle;
use Database\Seeders\BudgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the placeholder seeder.
 *
 * The seeder exists because the budget_v2 database lost its contents on
 * 2026-09-26. Its values are invented placeholders, not recovered data, so the
 * point of these tests is not that the specific names are right -- it is that
 * whatever the seeder writes is actually USABLE: the pages render it, and it
 * survives a round trip through the real controllers.
 *
 * A seeder that produces rows the app cannot edit is worse than no seeder,
 * because the breakage shows up later and looks unrelated.
 */
class BudgetSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_two_accounts_two_categories_and_one_meta_row(): void
    {
        $this->seed(BudgetSeeder::class);

        $this->assertSame(2, Account::count());
        $this->assertSame(2, Category::count());
        $this->assertSame(1, DB::table('meta')->count());
    }

    public function test_the_card_account_ships_with_a_due_day_and_the_cash_one_does_not(): void
    {
        $this->seed(BudgetSeeder::class);

        $card = Account::where('type', 'card')->firstOrFail();
        $cash = Account::where('type', 'cash')->firstOrFail();

        // AccountMetaData applies `required_if:type,card` to both `due` and
        // `statement_day`, so a card seeded without them cannot be saved when it
        // is later edited.
        $this->assertNotNull($card->meta);
        $this->assertNotEmpty($card->meta->meta['due']);
        $this->assertNotEmpty($card->meta->meta['statement_day']);

        $this->assertNull($cash->fresh()->meta);
    }

    public function test_the_seeded_card_terms_form_a_usable_statement_cycle(): void
    {
        // The end-to-end reason both days are seeded rather than just `due`:
        // CardStatementCycle::fromMeta refuses to build a cycle unless both are
        // numeric, so a card carrying only a payment term would silently derive no
        // due date for any charge made against it.
        $this->seed(BudgetSeeder::class);

        $card = Account::where('type', 'card')->firstOrFail();
        $cycle = CardStatementCycle::fromMeta($card->meta->meta);

        $this->assertNotNull($cycle, 'The seeded card must yield a statement cycle.');
        $this->assertSame(25, $cycle->statementDay());
        $this->assertSame(15, $cycle->termDays());
    }

    public function test_the_payment_term_is_truthy(): void
    {
        // AccountController builds the meta payload with collect(...)->filter(),
        // which strips falsy values. A payment term of "0" or "" would be dropped on
        // the next save, silently deleting the meta row from a card account.
        $this->seed(BudgetSeeder::class);

        $card = Account::where('type', 'card')->firstOrFail();
        $due = $card->meta->meta['due'];

        $this->assertNotEmpty($due, 'Seeded payment term must be truthy or it vanishes on the next save.');
        $this->assertLessThanOrEqual(28, mb_strlen((string) $due));
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(BudgetSeeder::class);
        $this->seed(BudgetSeeder::class);
        $this->seed(BudgetSeeder::class);

        $this->assertSame(2, Account::count());
        $this->assertSame(2, Category::count());
        $this->assertSame(1, DB::table('meta')->count());
    }

    public function test_the_accounts_page_renders_the_seeded_rows(): void
    {
        $this->seed(BudgetSeeder::class);

        $response = $this->get('/accounts');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('account')
            ->has('data.data', 2)
        );
    }

    public function test_a_seeded_account_can_be_updated_through_the_real_endpoint(): void
    {
        // The end-to-end check that matters: if the seeded shape did not match
        // what AccountController::update expects, this would come back as a
        // 302 carrying 'error db...' rather than a successful update.
        $this->seed(BudgetSeeder::class);

        $card = Account::where('type', 'card')->firstOrFail();

        $response = $this->put("/accounts/{$card->id}", [
            'id' => $card->id,
            'name' => $card->name,
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'USD',
            'meta_data' => ['due' => '20', 'statement_day' => 25],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('message', "Account [{$card->name}] updated");

        $this->assertSame('USD', $card->fresh()->ccy);
        $this->assertSame('20', (string) $card->fresh()->meta->meta['due']);
    }

    public function test_a_seeded_category_can_be_updated_through_the_real_endpoint(): void
    {
        $this->seed(BudgetSeeder::class);

        $category = Category::firstOrFail();

        $response = $this->put("/categories/{$category->id}", [
            'id' => $category->id,
            'name' => 'GROCERIES',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('GROCERIES', $category->fresh()->name);
    }
}
