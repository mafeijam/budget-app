<?php

namespace Tests\Feature;

use App\DTO\AccountData;
use App\DTO\AccountMetaData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountDataTest extends TestCase
{
    // The settlement link resolves the target account from the database, so this
    // DTO is no longer a pure function of its payload. The suite used to run
    // without a database at all, which was a fiction: it held only because no
    // rule or guard reached one.
    use RefreshDatabase;

    private int $cashId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashId = Account::create([
            'name' => 'Bank',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'HKD',
        ])->id;
    }

    private function postRequest(array $overrides = []): Request
    {
        return Request::create('/accounts', 'POST', array_merge([
            'name' => 'Test Account',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'USD',
            'meta_data' => ['due' => 15, 'statement_day' => 25],
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
        $this->assertNull($empty['settlement_account_id']);
        $this->assertSame(['due' => null, 'statement_day' => null], $empty['meta_data']);
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
        $this->assertSame(15, $data->meta_data->due);
        $this->assertSame(25, $data->meta_data->statement_day);
    }

    public function test_to_array_serializes_nested_meta_data(): void
    {
        $array = AccountData::from($this->postRequest())->toArray();

        $this->assertSame('Test Account', $array['name']);
        $this->assertSame(
            ['due' => 15, 'statement_day' => 25],
            $array['meta_data']
        );
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

        // `nullable` has to lead. A null counts as present to the validator, so
        // without it the `integer` and `between` below both fire on the null a
        // blank form field and empty() produce. required_if is implicit and
        // still runs, so a card with no due day is rejected.
        $this->assertSame(['nullable', 'required_if:type,card', 'integer', 'between:1,31'], $rules['due']);
    }

    public function test_meta_data_rules_for_due_and_statement_day_match(): void
    {
        // Both are day numbers over the same calendar, so their rules are
        // identical. They drifted -- `due` carried `max:28`, a string-length
        // check over a range that disagreed with CardStatementCycle::guardDay's
        // 1-31 -- and only a test comparing the two would have noticed.
        $rules = AccountMetaData::rules();

        $this->assertSame($rules['statement_day'], $rules['due']);
    }

    public function test_meta_data_attributes_rename_due_for_display(): void
    {
        // Without this the validation error reads "statement day" already via
        // Laravel's snake->sentence casing, but being explicit keeps it aligned
        // with the `due` entry and avoids depending on that casing.
        //
        // "due day", not "due date": the field holds a day of month, and calling
        // it a date is what let a date-shaped value sit in the fixtures looking
        // reasonable.
        $this->assertSame(
            ['due' => 'due day', 'statement_day' => 'statement day'],
            AccountMetaData::attributes()
        );
    }

    public function test_meta_data_requires_both_card_days_not_just_the_due_day(): void
    {
        // A due day alone cannot place a charge in a statement period: many
        // different statement days produce the same due day, so "when is it due"
        // does not answer "which statement is this in". Both are required, and
        // `due` alone is now insufficient rather than merely incomplete.
        $rules = AccountMetaData::rules();

        $this->assertContains('required_if:type,card', $rules['statement_day']);
        $this->assertContains('integer', $rules['statement_day']);
        $this->assertContains('between:1,31', $rules['statement_day']);
    }

    public function test_a_null_statement_day_is_not_an_integer_error(): void
    {
        // A blank number field, and AccountData::empty() both produce null, and
        // the validator treats a null value as present -- so without `nullable`
        // both `integer` and `between` would fire and reject a perfectly good
        // cash account whose form was never touched. `required_if` is implicit
        // and survives, which is what still rejects a card with no statement
        // day.
        $rules = AccountMetaData::rules();

        $this->assertContains('nullable', $rules['statement_day']);
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
                $overrides = ['type' => $type->value, 'status' => $status->value];

                // A securities account additionally has to say where it settles.
                // Supplied rather than relaxed, so this test keeps testing the one
                // thing it is named for instead of drifting into the settlement
                // rules.
                if ($type === AccountType::Security) {
                    $overrides['settlement_account_id'] = $this->cashId;
                }

                $data = AccountData::from($this->postRequest($overrides));

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
