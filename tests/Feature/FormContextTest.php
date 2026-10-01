<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Add menu opens a form over any page with what /forms/{form} returns, and the form's
 * own page opens it with its props. The two have to be the same props, or the dialog over
 * the forecast offers what the dialog on the list does not.
 */
class FormContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Something in every list a form offers, so an empty list cannot pass for a match.
        Account::create(['name' => 'Savings', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        Account::create(['name' => 'Card', 'status' => 'active', 'type' => 'card', 'ccy' => 'HKD']);
        Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        Category::create(['name' => 'Food']);
    }

    /** @return array<string, array{string, string}> */
    public static function forms(): array
    {
        return [
            'transaction' => ['transaction', '/transactions'],
            'account' => ['account', '/accounts'],
            'category' => ['category', '/categories'],
            'recurring' => ['recurring', '/recurring'],
        ];
    }

    #[DataProvider('forms')]
    public function test_a_forms_props_are_its_own_pages(string $form, string $page): void
    {
        $context = $this->getJson("/forms/{$form}")->assertOk()->json();

        $this->assertSame($page, $context['meta']['path']);
        $this->assertArrayHasKey('formEmpty', $context);

        $this->get($page)->assertInertia(function (Assert $inertia) use ($context) {
            foreach ($context as $key => $value) {
                // The page's meta adds the list's sort, and its options the filter's lists:
                // what the form reads of each is the form's, key by key.
                if (in_array($key, ['meta', 'options'], true)) {
                    foreach ($value as $inner => $innerValue) {
                        $inertia->where("{$key}.{$inner}", $innerValue);
                    }

                    continue;
                }

                $inertia->where($key, $value);
            }

            return $inertia->etc();
        });
    }

    public function test_an_unknown_form_is_not_found(): void
    {
        $this->getJson('/forms/nothing')->assertNotFound();
    }
}
