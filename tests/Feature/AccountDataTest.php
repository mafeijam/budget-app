<?php

namespace Tests\Feature;

use App\DTO\AccountData;
use App\DTO\AccountMetaData;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Unique;
use Tests\TestCase;

class AccountDataTest extends TestCase
{
    private function postRequest(array $overrides = []): Request
    {
        return Request::create('/accounts', 'POST', array_merge([
            'name' => 'Test Account',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'USD',
            'meta_data' => ['due' => '2026-10-01'],
        ], $overrides));
    }

    public function test_empty_returns_a_plain_array_keyed_by_property(): void
    {
        $empty = AccountData::empty(['status' => 'active']);

        // NOTE: empty() returns an array, NOT a Data object. The controller
        // passes it straight to Inertia as a form-defaults object.
        $this->assertIsArray($empty);
        $this->assertSame('active', $empty['status']);
        $this->assertNull($empty['name']);
        $this->assertNull($empty['id']);
        $this->assertNull($empty['created_at']);
        $this->assertSame(['due' => null], $empty['meta_data']);
    }

    public function test_from_request_populates_every_property(): void
    {
        $data = AccountData::from($this->postRequest());

        $this->assertNull($data->id);
        $this->assertSame('Test Account', $data->name);
        $this->assertSame('active', $data->status);
        $this->assertSame('card', $data->type);
        $this->assertSame('USD', $data->ccy);
        $this->assertNotNull($data->created_at);
        $this->assertInstanceOf(AccountMetaData::class, $data->meta_data);
        $this->assertSame('2026-10-01', $data->meta_data->due);
    }

    public function test_to_array_serializes_nested_meta_data(): void
    {
        $array = AccountData::from($this->postRequest())->toArray();

        $this->assertSame('Test Account', $array['name']);
        $this->assertSame(['due' => '2026-10-01'], $array['meta_data']);
    }

    public function test_except_returns_a_data_object_without_the_named_property(): void
    {
        $data = AccountData::from($this->postRequest());
        $withoutMeta = $data->except('meta_data');

        $this->assertInstanceOf(AccountData::class, $withoutMeta);
        $this->assertArrayNotHasKey('meta_data', $withoutMeta->toArray());
        $this->assertSame('Test Account', $withoutMeta->toArray()['name']);
    }

    public function test_rules_require_a_string_name_that_is_unique(): void
    {
        $rules = AccountData::rules(Request::create('/accounts'));

        $this->assertSame('required', $rules['name'][0]);
        $this->assertSame('string', $rules['name'][1]);
        $this->assertInstanceOf(Unique::class, $rules['name'][2]);
    }

    public function test_meta_data_rules_require_due_when_type_is_card(): void
    {
        $rules = AccountMetaData::rules();

        $this->assertSame('required_if:type,card', $rules['due'][0]);
        $this->assertSame('max:28', $rules['due'][1]);
    }

    public function test_meta_data_attributes_rename_due_for_display(): void
    {
        $this->assertSame(['due' => 'due date'], AccountMetaData::attributes());
    }
}
