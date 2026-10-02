<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The forms offer categories most used first, so the usual one is near the top. */
class CategoryOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_forms_list_categories_by_how_often_they_are_used(): void
    {
        $bank = Account::create(['name' => 'Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $rarely = Category::create(['name' => 'ALPHA']);
        $often = Category::create(['name' => 'ZULU']);
        Category::create(['name' => 'NEVER']);
        Category::create(['name' => 'BRAVO']);

        foreach ([$often, $often, $often, $rarely] as $category) {
            Transaction::create([
                'account_id' => $bank->id, 'category_id' => $category->id, 'date' => '2026-01-01',
                'type' => 'withdraw', 'description' => 'Spent', 'amount' => '1', 'ccy' => 'HKD', 'status' => 'posted',
            ]);
        }

        // Used most, then used, then by name among the unused.
        $expected = ['ZULU', 'ALPHA', 'BRAVO', 'NEVER'];

        foreach (['transaction', 'recurring'] as $form) {
            $this->getJson("/forms/{$form}")->assertOk()->assertJsonPath('options.categories.*.label', $expected);
        }
    }
}
