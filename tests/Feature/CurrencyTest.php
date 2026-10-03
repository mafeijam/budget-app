<?php

namespace Tests\Feature;

use App\Enums\Currency;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
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
}
