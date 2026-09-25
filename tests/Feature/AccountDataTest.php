<?php

namespace Tests\Feature;

use App\DTO\AccountData;
use App\DTO\AccountMetaData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
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
        $this->assertSame(AccountStatus::Active, $data->status);
        $this->assertSame(AccountType::Card, $data->type);
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

    /**
     * Asserts that a payload override is rejected on a specific field.
     *
     * The error bag is inspected rather than the exception type so that an
     * unrelated rule firing first (notably the `unique` name rule) cannot mask
     * the field actually under test.
     */
    private function assertFieldRejected(array $overrides, string $field): void
    {
        try {
            AccountData::from($this->postRequest($overrides));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return;
        }

        $this->fail("Expected [{$field}] to be rejected, but the payload validated.");
    }

    public function test_type_must_be_one_of_the_known_account_types(): void
    {
        $this->assertFieldRejected(['type' => 'banana'], 'type');
    }

    public function test_status_must_be_one_of_the_known_account_statuses(): void
    {
        $this->assertFieldRejected(['status' => 'purple'], 'status');
    }

    public function test_ccy_is_capped_at_three_characters(): void
    {
        $this->assertFieldRejected(['ccy' => 'HK Dollar'], 'ccy');
    }

    public function test_every_declared_account_type_and_status_is_accepted(): void
    {
        foreach (AccountType::cases() as $type) {
            foreach (AccountStatus::cases() as $status) {
                $data = AccountData::from($this->postRequest([
                    'type' => $type->value,
                    'status' => $status->value,
                ]));

                $this->assertSame($type, $data->type);
                $this->assertSame($status, $data->status);
            }
        }
    }

    public function test_to_array_keeps_enum_properties_as_plain_strings(): void
    {
        // Regression guard. The Vue form and every Inertia response read these
        // two fields as strings, so if the enums ever leak into the serialised
        // payload the whole front end breaks while the tests still look green.
        $array = AccountData::from($this->postRequest())->toArray();

        $this->assertSame('card', $array['type']);
        $this->assertSame('active', $array['status']);
    }

    public function test_collect_hydrates_enum_properties_from_database_strings(): void
    {
        // Wrapped in collect() because AccountData::collect() over a plain
        // array of rows returns an array, not a Support collection.
        $rows = collect(AccountData::collect([
            ['id' => 1, 'name' => 'A', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD'],
            ['id' => 2, 'name' => 'B', 'status' => 'inactive', 'type' => 'security', 'ccy' => 'USD'],
        ]));

        $this->assertSame(AccountStatus::Active, $rows->first()->status);
        $this->assertSame(AccountType::Cash, $rows->first()->type);
        $this->assertSame(AccountStatus::Inactive, $rows->last()->status);
        $this->assertSame(AccountType::Security, $rows->last()->type);
    }

    public function test_card_accounts_still_require_a_due_date(): void
    {
        // Regression guard for the nested `required_if:type,card` rule. It has
        // to keep resolving against the RAW input now that `type` is an enum,
        // otherwise every card account can be saved with no due date.
        $this->assertFieldRejected(
            ['type' => 'card', 'meta_data' => []],
            'meta_data.due'
        );
    }

    public function test_non_card_accounts_do_not_require_a_due_date(): void
    {
        // The counterpart to the rule above: this is the branch that makes
        // meta deletion reachable, so it must stay permitted.
        $data = AccountData::from($this->postRequest([
            'type' => 'cash',
            'meta_data' => [],
        ]));

        $this->assertNull($data->meta_data->due);
    }
}
