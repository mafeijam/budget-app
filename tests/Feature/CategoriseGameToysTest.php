<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriseGameToysTest extends TestCase
{
    use RefreshDatabase;

    private Account $saving;

    private Account $card;

    private Category $gameToy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saving = Account::create(['name' => 'SAVING', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $this->card = Account::create(['name' => 'MASTER', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        $this->gameToy = Category::create(['name' => 'GAME & TOY']);
    }

    public function test_cash_rows_for_opcg_and_gcg_are_filed_under_game_and_toy(): void
    {
        $opcg = $this->row($this->saving, 'OPCG');
        $gcg = $this->row($this->saving, 'GCG');
        $suffixed = $this->row($this->saving, 'OPCG 18,970 YEN');
        $lower = $this->row($this->saving, 'gcg');
        $ua = $this->row($this->saving, 'UA');
        $uaTcg = $this->row($this->saving, 'UA TCG');

        $this->artisan('transactions:categorise-game-toys')->expectsOutputToContain('6 rows to categorise')->assertSuccessful();
        $this->assertNull($opcg->fresh()->category_id, 'A dry run writes nothing.');

        $this->artisan('transactions:categorise-game-toys', ['--apply' => true])->expectsOutputToContain('Categorised 6 rows')->assertSuccessful();

        foreach ([$opcg, $gcg, $suffixed, $lower, $ua, $uaTcg] as $row) {
            $this->assertSame($this->gameToy->id, $row->fresh()->category_id);
        }

        $this->artisan('transactions:categorise-game-toys', ['--apply' => true])->expectsOutputToContain('Categorised 0 rows')->assertSuccessful();
    }

    public function test_only_cash_rows_whose_description_names_the_word_are_touched(): void
    {
        $charge = $this->row($this->card, 'OPCG', 'charge');
        $other = $this->row($this->saving, 'LUNCH');
        $inside = $this->row($this->saving, 'AGCGA');
        $meituan = $this->row($this->saving, 'BUY 200 SHARES 3690 MEITUAN-W @ 328');
        $annual = $this->row($this->saving, 'REFUND SC ANNUAL FEE', 'deposit');

        $this->artisan('transactions:categorise-game-toys', ['--apply' => true])->expectsOutputToContain('Categorised 0 rows')->assertSuccessful();

        foreach ([$charge, $other, $inside, $meituan, $annual] as $row) {
            $this->assertNull($row->fresh()->category_id);
        }
    }

    public function test_a_row_already_filed_elsewhere_is_reported_and_left_alone(): void
    {
        $food = Category::create(['name' => 'FOOD & DRINK']);
        $kept = $this->row($this->saving, 'GCG', 'withdraw', $food);
        $open = $this->row($this->saving, 'GCG');

        $this->artisan('transactions:categorise-game-toys', ['--apply' => true])
            ->expectsOutputToContain('Left alone, already in another category')
            ->expectsOutputToContain('Categorised 1 rows')
            ->assertSuccessful();

        $this->assertSame($food->id, $kept->fresh()->category_id);
        $this->assertSame($this->gameToy->id, $open->fresh()->category_id);
    }

    public function test_it_fails_without_the_category_rather_than_inventing_one(): void
    {
        $row = $this->row($this->saving, 'OPCG');
        $this->gameToy->delete();

        $this->artisan('transactions:categorise-game-toys', ['--apply' => true])->assertFailed();

        $this->assertNull($row->fresh()->category_id);
        $this->assertSame(0, Category::count());
    }

    private function row(Account $account, string $description, string $type = 'withdraw', ?Category $category = null): Transaction
    {
        return Transaction::create([
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'date' => '2026-03-01',
            'type' => $type,
            'description' => $description,
            'amount' => '100',
            'ccy' => 'HKD',
            'status' => 'posted',
        ]);
    }
}
