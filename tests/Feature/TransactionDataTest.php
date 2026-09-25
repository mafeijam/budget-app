<?php

namespace Tests\Feature;

use App\DTO\TransactionData;
use App\Models\Account;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransactionDataTest extends TestCase
{
    // Both foreign keys are validated with `exists:`, so the referenced rows
    // have to be real. They are created per test and rolled back by the
    // transaction RefreshDatabase wraps each test in.
    use RefreshDatabase;

    private int $accountId;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountId = Account::create([
            'name' => 'Account A',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'HKD',
        ])->id;

        $this->categoryId = Category::create(['name' => 'Food'])->id;
    }

    private function postRequest(array $overrides = []): Request
    {
        return Request::create('/transactions', 'POST', array_merge([
            'account_id' => $this->accountId,
            'category_id' => $this->categoryId,
            'date' => '2026-01-01',
            'type' => 'debit',
            'description' => 'Coffee',
            'amount' => '4.5000',
            'ccy' => 'USD',
        ], $overrides));
    }

    public function test_empty_returns_a_plain_array_keyed_by_property(): void
    {
        $empty = TransactionData::empty();

        $this->assertIsArray($empty);
        $this->assertNull($empty['account_id']);
        $this->assertNull($empty['created_at']);
    }

    public function test_from_request_populates_properties_and_defaults_created_at(): void
    {
        $data = TransactionData::from($this->postRequest());

        $this->assertSame($this->accountId, $data->account_id);
        $this->assertSame($this->categoryId, $data->category_id);
        $this->assertSame('2026-01-01', $data->date);
        $this->assertSame('debit', $data->type);
        $this->assertSame('Coffee', $data->description);
        $this->assertSame('4.5000', $data->amount);
        $this->assertSame('USD', $data->ccy);
        $this->assertNotNull($data->created_at);
    }

    public function test_created_at_resolves_to_the_carbon_class(): void
    {
        // Regression guard: TransactionData type-hints ?Carbon but was missing
        // `use Carbon\Carbon`, so the property resolved to App\DTO\Carbon and
        // the constructor's `$this->created_at ??= now()` threw a TypeError.
        $data = TransactionData::from($this->postRequest());

        $this->assertInstanceOf(Carbon::class, $data->created_at);
    }

    public function test_to_array_serializes_created_at(): void
    {
        $array = TransactionData::from($this->postRequest())->toArray();

        $this->assertArrayHasKey('created_at', $array);
        $this->assertNotNull($array['created_at']);
    }

    /**
     * Asserts that a payload override is rejected on a specific field.
     *
     * The error bag is inspected rather than the exception type so that an
     * unrelated rule firing first cannot mask the field under test.
     */
    private function assertFieldRejected(array $overrides, string $field): void
    {
        try {
            TransactionData::from($this->postRequest($overrides));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return;
        }

        $this->fail("Expected [{$field}] to be rejected, but the payload validated.");
    }

    public function test_amount_must_be_numeric(): void
    {
        // The column is decimal(12,4) and MySQL runs in strict mode, so a
        // non-numeric amount used to reach the driver and surface as a
        // database error rather than a validation error.
        $this->assertFieldRejected(['amount' => 'not-a-number'], 'amount');
    }

    public function test_amount_may_not_carry_more_than_four_decimal_places(): void
    {
        // MySQL would silently round a fifth decimal place.
        $this->assertFieldRejected(['amount' => '4.50000'], 'amount');
    }

    public function test_amount_may_not_exceed_the_column_precision(): void
    {
        // decimal(12,4) leaves eight digits for the integer part.
        $this->assertFieldRejected(['amount' => '999999999.9999'], 'amount');
    }

    public function test_date_must_be_iso_formatted(): void
    {
        $this->assertFieldRejected(['date' => '01/01/2026'], 'date');
    }

    public function test_account_id_must_reference_an_existing_account(): void
    {
        // Without `exists:` a bad id reached the foreign key and came back as
        // a database error instead of a validation error.
        $this->assertFieldRejected(['account_id' => 999999], 'account_id');
    }

    public function test_category_id_must_reference_an_existing_category(): void
    {
        $this->assertFieldRejected(['category_id' => 999999], 'category_id');
    }

    public function test_description_length_matches_the_column(): void
    {
        $this->assertFieldRejected(['description' => str_repeat('x', 256)], 'description');
    }

    public function test_ccy_is_capped_at_three_characters(): void
    {
        $this->assertFieldRejected(['ccy' => 'HK Dollar'], 'ccy');
    }

    public function test_only_the_extra_constraints_are_declared(): void
    {
        // `required` and the type checks are derived from the constructor
        // property types by spatie, so rules() only adds what the types cannot
        // express. This also documents the shape returned by the debug stub in
        // TransactionController::store().
        $rules = TransactionData::rules();

        $this->assertSame(['exists:accounts,id'], $rules['account_id']);
        $this->assertSame(['exists:categories,id'], $rules['category_id']);
        $this->assertSame(['date_format:Y-m-d'], $rules['date']);
        $this->assertSame(['decimal:0,4', 'max:99999999.9999'], $rules['amount']);
        $this->assertSame(['max:255'], $rules['type']);
        $this->assertSame(['max:255'], $rules['description']);
        $this->assertSame(['size:3'], $rules['ccy']);
    }
}
