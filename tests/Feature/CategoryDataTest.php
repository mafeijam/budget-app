<?php

namespace Tests\Feature;

use App\DTO\CategoryData;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Unique;
use Tests\TestCase;

class CategoryDataTest extends TestCase
{
    public function test_empty_returns_a_plain_array_keyed_by_property(): void
    {
        $empty = CategoryData::empty();

        $this->assertIsArray($empty);
        $this->assertSame(
            ['id', 'name', 'created_at'],
            array_keys($empty)
        );
        $this->assertNull($empty['name']);
    }

    public function test_from_request_populates_name_and_defaults_created_at(): void
    {
        $data = CategoryData::from(Request::create('/categories', 'POST', [
            'name' => 'Groceries',
        ]));

        $this->assertSame('Groceries', $data->name);
        $this->assertNull($data->id);
        $this->assertNotNull($data->created_at);
    }

    public function test_to_array_does_not_expose_unrelated_account_fields(): void
    {
        $array = CategoryData::from(
            Request::create('/categories', 'POST', ['name' => 'Groceries'])
        )->toArray();

        $this->assertSame(['id', 'name', 'created_at'], array_keys($array));
    }

    public function test_rules_require_a_string_name_that_is_unique(): void
    {
        $rules = CategoryData::rules(Request::create('/categories'));

        $this->assertSame('required', $rules['name'][0]);
        $this->assertSame('string', $rules['name'][1]);
        $this->assertInstanceOf(Unique::class, $rules['name'][2]);
    }
}
