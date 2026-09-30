<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_value_is_a_three_character_uppercase_code(): void
    {
        // The column is varchar(255) and the DTO no longer carries a length cap,
        // so the enum is the only thing keeping the stored shape uniform. These
        // three properties are what make 'HKD' sortable and recognisable in the
        // account table, the settlement picker's label, and any future export.
        foreach (Currency::cases() as $currency) {
            $this->assertMatchesRegularExpression(
                '/^[A-Z]{3}$/',
                $currency->value,
                "{$currency->value} is not an uppercase alphabetic code"
            );
        }
    }

    public function test_every_case_has_a_label_starting_with_its_own_code(): void
    {
        // The dropdown emits the code and the user reads the name, so a label
        // that led with something else -- or that named a different currency
        // than the value it belongs to -- would store one code while displaying
        // another.
        foreach (Currency::cases() as $currency) {
            $label = $currency->label();

            $this->assertStringStartsWith($currency->value, $label);
            $this->assertStringContainsString('—', $label, "{$currency->value} label has no name after the code");
            $this->assertGreaterThan(
                strlen($currency->value) + 3,
                strlen($label),
                "{$currency->value} label carries no name"
            );
        }
    }

    public function test_the_case_count_is_pinned(): void
    {
        // This looks like a test that should be deleted the moment it is
        // inconvenient, and that is the point. AccountController sends
        // Currency::cases() to the browser, so a case added here changes what the
        // form offers and what a test elsewhere assumes the form offers. Pinning
        // the count makes that an explicit decision in one place rather than a
        // failure discovered in AccountControllerTest or by a user who cannot
        // find their currency.
        //
        // Update this when adding a case, and check the fixtures: a currency
        // outside this set becomes unsaveable.
        $this->assertCount(7, Currency::cases());
    }

    public function test_a_currency_outside_the_set_cannot_reach_the_column(): void
    {
        // The whole point of the enum. Writing straight to the table is what a
        // seeder, an import or a tinker session would do, and it is the reason
        // the database has no CHECK constraint: there is no list at the schema
        // level, so this guarantee is application-level only. Pinned so that is
        // a known fact rather than a hidden gap.
        Account::create(['name' => 'Loose', 'status' => 'active', 'type' => 'cash', 'ccy' => 'ZZZ']);

        $this->assertDatabaseHas('accounts', ['ccy' => 'ZZZ']);
    }
}
