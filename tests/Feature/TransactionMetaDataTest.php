<?php

namespace Tests\Feature;

use App\DTO\TransactionMetaData;
use App\Enums\TransactionType;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the type-specific fields a transaction carries in its meta bag.
 *
 * The transactions table is shared by cash, card and securities rows, so most of
 * what a row needs to mean lives here rather than in columns: a charge has a
 * merchant, a trade has a symbol and a price. That makes the rules conditional
 * on the transaction type, which is the part worth testing -- a merchant
 * required on a dividend, or a unit price required on a card payment, would both
 * reject rows a user is entitled to record.
 *
 * The trade amount is derived here rather than supplied, because it is a
 * product of two numbers the client sends. See derivedAmount() for why the
 * arithmetic is decimal rather than float.
 */
class TransactionMetaDataTest extends TestCase
{
    /**
     * Validate one meta field against a root payload, the way the DTO will.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $root
     */
    private function rejects(string $field, array $meta, array $root): bool
    {
        return Validator::make(
            $root + ['meta_data' => $meta],
            ['meta_data.'.$field => TransactionMetaData::rules()[$field]]
        )->fails();
    }

    public function test_a_charge_requires_a_merchant(): void
    {
        $this->assertTrue($this->rejects('merchant', ['merchant' => null], ['type' => 'charge']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonChargeTypeProvider(): array
    {
        return [
            'expense' => ['expense'],
            'income' => ['income'],
            'payment' => ['payment'],
            'buy' => ['buy'],
            'sell' => ['sell'],
            'dividend' => ['dividend'],
        ];
    }

    #[DataProvider('nonChargeTypeProvider')]
    public function test_no_other_type_requires_a_merchant(string $type): void
    {
        $this->assertFalse(
            $this->rejects('merchant', ['merchant' => null], ['type' => $type]),
            "A {$type} should not be asked for a merchant."
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function tradeTypeProvider(): array
    {
        return ['buy' => ['buy'], 'sell' => ['sell']];
    }

    #[DataProvider('tradeTypeProvider')]
    public function test_a_trade_requires_a_symbol_a_quantity_and_a_price(string $type): void
    {
        foreach (['symbol', 'quantity', 'unit_price'] as $field) {
            $this->assertTrue(
                $this->rejects($field, [$field => null], ['type' => $type]),
                "A {$type} should require a {$field}."
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonTradeTypeProvider(): array
    {
        return [
            'expense' => ['expense'],
            'income' => ['income'],
            'charge' => ['charge'],
            'payment' => ['payment'],
            'dividend' => ['dividend'],
        ];
    }

    #[DataProvider('nonTradeTypeProvider')]
    public function test_no_other_type_requires_trade_fields(string $type): void
    {
        foreach (['symbol', 'quantity', 'unit_price'] as $field) {
            $this->assertFalse(
                $this->rejects($field, [$field => null], ['type' => $type]),
                "A {$type} should not be asked for a {$field}."
            );
        }
    }

    public function test_a_dividend_needs_no_trade_fields_though_it_lives_on_the_same_account(): void
    {
        // The reason this is worth a test: a dividend is recorded on a
        // securities account alongside the trades, so a rule keyed on the
        // account type rather than the transaction type would demand a symbol
        // and a unit price for it.
        $this->assertFalse($this->rejects('symbol', ['symbol' => null], ['type' => 'dividend']));
        $this->assertFalse($this->rejects('quantity', ['quantity' => null], ['type' => 'dividend']));
    }

    public function test_fees_are_optional_on_a_trade(): void
    {
        // A broker that charges nothing is common enough that requiring a fee
        // would be a pointless obstacle, and defaulting to zero is not a
        // behaviour worth encoding in a rule.
        $this->assertFalse($this->rejects('fees', ['fees' => null], ['type' => 'buy']));
    }

    public function test_fees_must_not_be_negative(): void
    {
        $this->assertTrue($this->rejects('fees', ['fees' => '-1'], ['type' => 'sell']));
    }

    public function test_a_quantity_must_be_positive(): void
    {
        $this->assertTrue($this->rejects('quantity', ['quantity' => '0'], ['type' => 'buy']));
    }

    public function test_the_required_rules_track_the_enum_rather_than_a_hand_written_list(): void
    {
        // The "required unless" lists are built from TransactionType, so a new
        // case is picked up automatically. This asserts they still agree with
        // derivesAmount() and with the one type that needs a merchant, which is
        // what would fail if someone ever hard-coded the lists again.
        $rules = TransactionMetaData::rules();

        foreach (TransactionType::cases() as $type) {
            $needsTradeFields = $type->derivesAmount();

            foreach (['symbol', 'quantity', 'unit_price'] as $field) {
                $this->assertSame(
                    $needsTradeFields,
                    $this->rejects($field, [$field => null], ['type' => $type->value]),
                    "{$field} required for {$type->value} disagrees with derivesAmount()."
                );
            }

            $this->assertSame(
                $type === TransactionType::Charge,
                $this->rejects('merchant', ['merchant' => null], ['type' => $type->value]),
                "merchant required for {$type->value} disagrees with it being a charge."
            );
        }
    }

    public function test_an_unrecognised_type_is_treated_as_needing_every_field(): void
    {
        // The exclusion lists are built from the enum, so a type the enum does
        // not know falls outside every list and therefore has to supply every
        // field. The enum rejects the type outright; this is the belt to that
        // braces, and it fails closed rather than open.
        foreach (['symbol', 'quantity', 'unit_price', 'merchant'] as $field) {
            $this->assertTrue(
                $this->rejects($field, [$field => null], ['type' => 'banana']),
                "An unknown type should have to supply {$field}."
            );
        }
    }

    public function test_no_rule_uses_a_conditional_form_this_laravel_does_not_have(): void
    {
        // Regression guard. `required_if_in` was written here first and this
        // version of Laravel has no such method, so it was accepted into the
        // rule array and then never run: every rule test against it passed
        // vacuously and a trade could be saved with no symbol and no quantity.
        // A rule that does not exist fails silently, so its absence has to be
        // asserted rather than discovered.
        //
        // This is a whitelist rather than a lookup, so a rule new to this bag
        // has to be added here deliberately -- which is the point. `date_format`
        // arrived with due_date; the rest predate it.
        // `prohibited` arrived with paired_transaction_id, which is the one rule here
        // that is neither nullable nor conditional on the root `type`.
        $supported = ['nullable', 'required_unless', 'max', 'decimal', 'gt', 'min', 'date_format', 'prohibited'];

        foreach (TransactionMetaData::rules() as $field => $rules) {
            foreach ($rules as $rule) {
                $name = explode(':', $rule)[0];

                $this->assertContains(
                    $name,
                    $supported,
                    "The rule '{$rule}' on {$field} is not one this test knows to be supported."
                );
            }
        }
    }

    private function meta(array $overrides = []): TransactionMetaData
    {
        return TransactionMetaData::from(array_merge([
            'merchant' => null,
            'symbol' => '0700.HK',
            'quantity' => '100',
            'unit_price' => '150.50',
            'fees' => null,
            'fx_rate' => null,
        ], $overrides));
    }

    public function test_it_derives_nothing_for_a_type_that_is_not_a_trade(): void
    {
        // The caller decides which types derive an amount, not this method: a
        // dividend is recorded on a securities account but its amount is simply
        // stated, so deriving one from a stale symbol would be nonsense.
        foreach ([
            TransactionType::Expense,
            TransactionType::Income,
            TransactionType::Charge,
            TransactionType::Payment,
            TransactionType::Dividend,
        ] as $type) {
            $this->assertNull(
                $this->meta()->derivedAmount($type),
                "{$type->value} should not derive an amount."
            );
        }
    }

    public function test_a_buy_costs_the_price_plus_the_fee(): void
    {
        // The amount is what leaves the account, so the brokerage is part of the
        // cost of the trade rather than a separate cost.
        $amount = $this->meta(['quantity' => '100', 'unit_price' => '150.50', 'fees' => '25.00'])
            ->derivedAmount(TransactionType::Buy);

        $this->assertSame('15075.0000', $amount);
    }

    public function test_a_sell_proceeds_by_the_price_minus_the_fee(): void
    {
        // Net of fees, and this is the whole reason it is net: the fee never
        // reaches the cash account, so a sell that adds it back would overstate
        // the balance by exactly the brokerage.
        $amount = $this->meta(['quantity' => '100', 'unit_price' => '150.50', 'fees' => '25.00'])
            ->derivedAmount(TransactionType::Sell);

        $this->assertSame('15025.0000', $amount);
    }

    public function test_a_trade_without_a_fee_is_just_price_times_quantity(): void
    {
        $meta = $this->meta(['quantity' => '100', 'unit_price' => '150.50']);

        $this->assertSame('15050.0000', $meta->derivedAmount(TransactionType::Buy));
        $this->assertSame('15050.0000', $meta->derivedAmount(TransactionType::Sell));
    }

    public function test_a_fractional_quantity_is_supported(): void
    {
        $meta = $this->meta(['quantity' => '0.5', 'unit_price' => '150.50']);

        $this->assertSame('75.2500', $meta->derivedAmount(TransactionType::Buy));
    }

    public function test_the_derived_amount_carries_the_full_scale_of_the_amount_column(): void
    {
        // decimal(12,4): exactly four decimal places, trailing zeros included.
        // A value written as "15075" instead of "15075.0000" is the same number,
        // but the fixed scale is what makes the column's arithmetic predictable.
        $this->assertSame(
            '15050.0000',
            $this->meta(['quantity' => '100', 'unit_price' => '150.50'])->derivedAmount(TransactionType::Buy)
        );
    }

    public function test_the_derived_amount_is_rounded_half_up(): void
    {
        // Three quantities at eight decimal places times a four-place price
        // produce twelve decimal places, so something has to give. Half-up is
        // the conventional choice for money, and it is stated here rather than
        // inherited from PHP's `precision` setting.
        $this->assertSame(
            '0.0073',
            $this->meta(['quantity' => '0.00007919', 'unit_price' => '92.0000'])
                ->derivedAmount(TransactionType::Buy)
        );
    }

    public function test_the_arithmetic_is_exact_decimal(): void
    {
        // Contract, not a demonstration of a bug: float64 happens to survive
        // these magnitudes, but it has no decimal semantics, so that is luck
        // rather than a guarantee, and a wider quantity or a change of scale
        // would break it silently. Decimal arithmetic makes the result exact
        // for any input the rules accept, and makes the rounding and the
        // magnitude check below explicit instead of emergent.
        $this->assertSame(
            '123456.7890',
            $this->meta(['quantity' => '12345.6789', 'unit_price' => '10.0000'])
                ->derivedAmount(TransactionType::Buy)
        );

        // Ten thousand units at four places, where the interesting part is that
        // the digit count stays exact rather than trailing off at float
        // precision.
        $this->assertSame(
            '99999999.0000',
            $this->meta(['quantity' => '10000', 'unit_price' => '9999.9999'])
                ->derivedAmount(TransactionType::Buy)
        );
    }

    public function test_an_amount_at_the_column_maximum_is_allowed(): void
    {
        // decimal(12,4) tops out at 99999999.9999. This is exactly it, so it
        // must be admitted -- an off-by-one in the guard would make the largest
        // representable trade impossible to record.
        $this->assertSame(
            '99999999.9999',
            $this->meta(['quantity' => '1', 'unit_price' => '99999999.9999'])
                ->derivedAmount(TransactionType::Buy)
        );
    }

    public function test_an_amount_past_the_column_maximum_is_rejected(): void
    {
        // Left to the database this would either throw a truncation error at
        // insert time or, worse, be silently rounded down -- losing money
        // without any error at all.
        $this->expectException(InvalidArgumentException::class);

        $this->meta(['quantity' => '1', 'unit_price' => '100000000.0000'])
            ->derivedAmount(TransactionType::Buy);
    }

    // ---------------------------------------------------------------------
    // The exchange rate
    // ---------------------------------------------------------------------

    public function test_a_rate_is_optional_whatever_the_type(): void
    {
        // NULL means "already in the account's own currency", which is the common
        // case. It must never be required, and must never be forced to a literal 1.
        foreach (TransactionType::cases() as $type) {
            $this->assertFalse(
                $this->rejects('fx_rate', ['fx_rate' => null], ['type' => $type->value]),
                "{$type->value} should not require an exchange rate"
            );
        }
    }

    public function test_a_rate_of_zero_or_less_is_refused(): void
    {
        // A rate of zero would divide an account's balance to nothing, and a
        // negative rate is meaningless. NULL is how you say "no conversion".
        $this->assertTrue($this->rejects('fx_rate', ['fx_rate' => '0'], ['type' => 'expense']));
        $this->assertTrue($this->rejects('fx_rate', ['fx_rate' => '-7.8'], ['type' => 'expense']));
    }

    public function test_a_rate_may_not_carry_more_than_eight_decimal_places(): void
    {
        // Eight because a rate needs more precision than money does: HKD per USD
        // is 7.8-something, and four places would not survive a conversion.
        $this->assertTrue($this->rejects('fx_rate', ['fx_rate' => '7.849512345'], ['type' => 'expense']));
    }

    public function test_a_rate_at_the_old_column_maximum_is_allowed(): void
    {
        // This was decimal(16,8): sixteen digits, eight after the point, so
        // 99999999.99999999 was exactly representable and must stay so. An
        // off-by-one in the guard would refuse the largest rate the schema used
        // to hold, which is a narrowing nobody asked for.
        $this->assertFalse(
            $this->rejects('fx_rate', ['fx_rate' => '99999999.99999999'], ['type' => 'expense'])
        );
    }

    public function test_a_rate_wider_than_the_old_column_is_refused(): void
    {
        // The one guard this move has to add rather than inherit. `decimal:0,8`
        // counts decimal places and says nothing about integer digits, so it let
        // a thirty-digit rate through; the column then refused it at insert time,
        // or rounded it down. With no column there is nothing left to refuse it,
        // and the DTO is the only gate -- so the max has to be stated here.
        $this->assertTrue(
            $this->rejects('fx_rate', ['fx_rate' => '100000000'], ['type' => 'expense'])
        );
    }

    public function test_a_sell_whose_fees_exceed_the_proceeds_is_rejected(): void
    {
        // amount is a positive magnitude and the direction of a trade comes from
        // its type, so there is nowhere to record a negative. A sell that nets
        // below zero is a data error -- usually fees entered against the wrong
        // side -- and must not quietly become a purchase.
        $this->expectException(InvalidArgumentException::class);

        $this->meta(['quantity' => '10', 'unit_price' => '5.00', 'fees' => '100.00'])
            ->derivedAmount(TransactionType::Sell);
    }

    public function test_a_sell_whose_fees_exactly_equal_the_proceeds_is_allowed(): void
    {
        // Zero is a legal magnitude: the trade closed with nothing left to
        // deposit. Only a negative is an error.
        $this->assertSame(
            '0.0000',
            $this->meta(['quantity' => '10', 'unit_price' => '5.00', 'fees' => '50.00'])
                ->derivedAmount(TransactionType::Sell)
        );
    }
}
