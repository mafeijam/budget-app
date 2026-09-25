<?php

namespace Tests\Feature;

use App\DTO\TransactionData;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

class TransactionDataTest extends TestCase
{
    private function postRequest(array $overrides = []): Request
    {
        return Request::create('/transactions', 'POST', array_merge([
            'account_id' => 1,
            'category_id' => 2,
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

        $this->assertSame(1, $data->account_id);
        $this->assertSame(2, $data->category_id);
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
}
