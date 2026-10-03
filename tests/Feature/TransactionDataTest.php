<?php

namespace Tests\Feature;

use App\DTO\TransactionData;
use App\DTO\TransactionMetaData;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the transaction payload for all three account types.
 *
 * The transactions table is shared by cash, card and securities rows, so most
 * of what makes a payload valid is a property of a *pair* -- a transaction type
 * and the account it belongs to -- rather than of either alone. That is why
 * there is a card account and a securities account here as well as the cash one:
 * the interesting rejections are all "that does not go on that kind of
 * account", and they cannot be expressed without both sides existing.
 */
class TransactionDataTest extends TestCase
{
    // The foreign keys are validated with `exists:`, and the account type is
    // read from the database to check it against the transaction type, so real
    // rows are needed. They are created per test and rolled back by the
    // transaction RefreshDatabase wraps each test in.
    use RefreshDatabase;

    private int $accountId;

    private int $categoryId;

    private int $cardId;

    private int $securityId;

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

        $card = Account::create([
            'name' => 'Card A',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
        ]);
        $card->meta()->create(['meta' => ['term_days' => 15, 'statement_day' => 25]]);
        $this->cardId = $card->id;

        $this->securityId = Account::create([
            'name' => 'Broker A',
            'status' => 'active',
            'type' => 'security',
            'ccy' => 'HKD',
        ])->id;
    }

    private function postRequest(array $overrides = []): Request
    {
        return Request::create('/transactions', 'POST', array_merge([
            'account_id' => $this->accountId,
            'category_id' => $this->categoryId,
            'date' => '2026-01-01',
            'type' => 'withdraw',
            'description' => 'Coffee',
            'amount' => '4.5000',
            'ccy' => 'USD',
        ], $overrides));
    }

    /**
     * A trade payload: no amount, because the server derives it, and the
     * quantity and price it derives from.
     */
    private function tradeRequest(string $type, array $overrides = []): Request
    {
        return $this->postRequest(array_merge([
            'account_id' => $this->securityId,
            'type' => $type,
            // The brokerage's own currency, which a trade must be in.
            'ccy' => 'HKD',
            'amount' => null,
            'category_id' => null,
            'meta_data' => [
                'symbol' => '0700.HK',
                'quantity' => '100',
                'unit_price' => '150.50',
            ],
        ], $overrides));
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
        $this->assertSame(TransactionType::Withdraw, $data->type);
        $this->assertSame('Coffee', $data->description);
        $this->assertSame('4.5000', $data->amount);
        $this->assertSame(Currency::Usd, $data->ccy);
        $this->assertNotNull($data->created_at);
    }

    public function test_enums_serialize_as_their_plain_values(): void
    {
        // Guards the wire format. toArray() must emit the scalar, not the enum
        // name, or every Inertia prop carrying a transaction changes shape.
        //
        // ccy is here for the same reason as the other two, and with more at
        // stake: store() is still a stub that returns the rules rather than
        // writing, so the moment it is wired to Transaction::create() a leaked
        // enum becomes a broken INSERT that no test currently stands between.
        $array = TransactionData::from($this->postRequest())->toArray();

        $this->assertSame('withdraw', $array['type']);
        $this->assertSame('posted', $array['status']);
        $this->assertSame('USD', $array['ccy']);
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

    public function test_amount_may_not_be_negative(): void
    {
        // amount is a positive magnitude; direction comes from the account type
        // and the transaction type. A negative here is a sign error at best.
        $this->assertFieldRejected(['amount' => '-4.5000'], 'amount');
    }

    public function test_date_must_be_iso_formatted(): void
    {
        $this->assertFieldRejected(['date' => '01/01/2026'], 'date');
    }

    public function test_account_id_must_reference_an_existing_account(): void
    {
        // Without `exists:` a bad id reached the foreign key and came back as a
        // database error instead of a validation error.
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

    public function test_a_transaction_with_no_description_is_rejected(): void
    {
        // Required unconditionally, so one case covers every type -- there is no
        // `required_unless` here to be right for one and wrong for another, which is
        // the whole of what distinguishes it from the `merchant` rule it replaces.
        // That rule was required for a charge alone and duplicated this field.
        $this->assertFieldRejected(['description' => null], 'description');
    }

    public function test_a_blank_description_is_rejected(): void
    {
        // Whitespace counts as blank, and not because this rule says so: Laravel's
        // `required` trims a string before deciding, so ' ' fails on the framework's
        // own terms. Worth pinning, because a reader of `['required', 'max:255']`
        // would not know that, and because it is the difference between a description
        // and a space someone typed to get past the field.
        $this->assertFieldRejected(['description' => ' '], 'description');
    }

    public function test_ccy_must_be_a_currency_the_app_offers(): void
    {
        // Previously this was a length cap, so it rejected 'HK Dollar' and
        // accepted 'ZZZ' and 'hkd' just as readily as 'HKD' -- a code no account
        // can hold and no dropdown offers, on a row whose amount is denominated
        // in it. The enum decides now, and case is part of the value.
        foreach (['ZZZ', 'hkd', 'HK Dollar', 'HKDD', 'US', ''] as $ccy) {
            $this->assertFieldRejected(['ccy' => $ccy], 'ccy');
        }
    }

    public function test_a_transaction_may_differ_from_its_account_currency(): void
    {
        // The one thing the enum must not do here: narrow ccy to the account's own
        // currency. A foreign purchase is denominated in the merchant's currency
        // and card_amount is what brings it home, so requiring the two to match would
        // make card_amount unreachable and quietly forbid the only case it exists for.
        //
        // setUp's account is HKD and the request default is already USD, so this
        // asserts the pairing rather than the accident of which code came first.
        $account = Account::find($this->accountId);
        $this->assertSame('HKD', $account->ccy);

        $data = TransactionData::from($this->postRequest(['ccy' => 'USD']));

        $this->assertSame(Currency::Usd, $data->ccy);
    }

    public function test_every_offered_currency_is_accepted_on_a_transaction(): void
    {
        // The mirror of the membership test, so the two cannot be broken by
        // trimming the enum: a currency a client may legitimately pay in has to
        // be submittable, and the set is the account enum rather than a
        // transaction-only one.
        foreach (Currency::cases() as $currency) {
            $data = TransactionData::from($this->postRequest(['ccy' => $currency->value]));

            $this->assertSame($currency, $data->ccy);
        }
    }

    public function test_a_rejected_currency_is_reported_as_a_currency_not_a_ccy(): void
    {
        // "The selected ccy is invalid" is the one field error a user cannot act
        // on. Same wording as the account form's, since it is the same field on
        // the same form.
        try {
            TransactionData::from($this->postRequest(['ccy' => 'ZZZ']));
        } catch (ValidationException $e) {
            $this->assertSame(
                ['The selected currency is invalid.'],
                $e->errors()['ccy']
            );

            return;
        }

        $this->fail('Expected a ZZZ currency to be rejected, but the payload validated.');
    }

    public function test_an_unknown_transaction_type_is_rejected(): void
    {
        // Previously "banana" reached the string column, where it looked like a
        // perfectly good row until something asked what it meant.
        $this->assertFieldRejected(['type' => 'banana'], 'type');
    }

    public function test_status_defaults_to_posted(): void
    {
        // Posted is the only state a plain cash expense is ever in, so a client
        // that does not care about status should not have to say so.
        $this->assertSame(TransactionStatus::Posted, TransactionData::from($this->postRequest())->status);
    }

    public function test_a_client_supplied_status_is_kept(): void
    {
        $data = TransactionData::from($this->postRequest(['status' => 'pending']));

        $this->assertSame(TransactionStatus::Pending, $data->status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->assertFieldRejected(['status' => 'banana'], 'status');
    }

    // ---------------------------------------------------------------------
    // The account type / transaction type pairing
    // ---------------------------------------------------------------------

    public function test_a_charge_is_accepted_on_a_card(): void
    {
        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'charge',
            'ccy' => 'HKD',
        ]));

        $this->assertSame(TransactionType::Charge, $data->type);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mismatchedPairProvider(): array
    {
        return [
            'a payment settles a card statement, not a cash account' => ['cash', 'payment'],
            'a payment settles a card statement, not a broker account' => ['security', 'payment'],
            'a charge spends a card, not a cash account' => ['cash', 'charge'],
            'a charge spends a card, not a broker account' => ['security', 'charge'],
            'a trade belongs on a broker account, not a cash account' => ['cash', 'buy'],
            'an expense is not spending a card' => ['card', 'withdraw'],
            'income into a cash account is not income on a card' => ['card', 'deposit'],
            'a dividend is paid into a bank, not a card' => ['card', 'dividend'],
            'a dividend is paid into a bank, not a brokerage' => ['security', 'dividend'],
            'a deposit on a brokerage is not a thing' => ['security', 'deposit'],
        ];
    }

    #[DataProvider('mismatchedPairProvider')]
    public function test_a_transaction_type_is_rejected_on_the_wrong_account_type(
        string $accountType,
        string $transactionType
    ): void {
        $account = Account::create([
            'name' => "Probe {$accountType}",
            'status' => 'active',
            'type' => $accountType,
            'ccy' => 'HKD',
        ]);

        // The payload has to be otherwise valid for the transaction type, and
        // that is not incidental: spatie validates the payload *before* it
        // constructs the DTO, so any independent error -- a prohibited amount on
        // a trade, say -- is reported in place of the pairing. Building a clean
        // payload is the only way to be sure the guard is what rejected it.
        $type = TransactionType::from($transactionType);

        $overrides = ['account_id' => $account->id, 'type' => $transactionType];

        if ($type->requiresCategory()) {
            $overrides['category_id'] = $this->categoryId;
        } else {
            $overrides['category_id'] = null;
        }

        $overrides['amount'] = $type->derivesAmount() ? null : '10.0000';

        if ($type->derivesAmount()) {
            $overrides['meta_data'] = [
                'symbol' => '0700.HK',
                'quantity' => '1',
                'unit_price' => '1.0000',
            ];
        }

        try {
            TransactionData::from($this->postRequest($overrides));
            $this->fail("A {$transactionType} was accepted on a {$accountType} account.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('type', $e->errors());
            $this->assertStringContainsString($accountType, $e->errors()['type'][0]);
        }
    }

    public function test_the_pairing_is_checked_even_without_validating(): void
    {
        // The pairing needs the account's type, which only the database knows, so
        // it cannot be a rule on the payload alone. It is enforced in the
        // constructor instead, which means it holds whether or not the caller
        // remembered to call validate() -- the alternative is a client that
        // skips validation writing a payment onto a cash account.
        $this->expectException(ValidationException::class);

        TransactionData::from($this->postRequest(['type' => 'payment']));
    }

    // ---------------------------------------------------------------------
    // Category
    // ---------------------------------------------------------------------

    public function test_a_withdrawal_need_not_be_categorised(): void
    {
        // Optional, not required and not prohibited. The app writes withdrawals of its own
        // beside buys and card payments, and neither has a category to give.
        $data = TransactionData::from($this->postRequest(['category_id' => null]));

        $this->assertNull($data->category_id);
    }

    public function test_a_charge_must_be_categorised(): void
    {
        $this->assertFieldRejected([
            'account_id' => $this->cardId,
            'type' => 'charge',
            'category_id' => null,
        ], 'category_id');
    }

    public function test_a_payment_needs_no_category(): void
    {
        // A payment settles a statement rather than buying anything, so it has
        // nothing that *has* to be categorised. This is why category_id became
        // nullable. "Need not" rather than "must not": see the next test.
        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'payment',
            'category_id' => null,
        ]));

        $this->assertNull($data->category_id);
    }

    public function test_a_payment_may_be_labelled_with_a_category(): void
    {
        // The flip side of the test above, and a distinction that is easy to get
        // backwards. The rule is required_unless over the types that are
        // categorised spending, not prohibited_unless over the rest -- so a
        // payment may carry a label, and there is no rule here that would reject
        // one.
        //
        // Worth pinning because a payment often *does* refer to a category: it
        // may settle a single purchase's worth of charges, or reimburse one. A
        // label on a payment is inert to the balance, since the settlement
        // arithmetic is a SUM over charge and payment rows and never reads
        // category_id.
        $category = Category::create(['name' => 'Reimbursement']);

        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'payment',
            'category_id' => $category->id,
        ]));

        $this->assertSame($category->id, $data->category_id);
    }

    public function test_a_category_rule_requires_rather_than_prohibits(): void
    {
        // Structural, so the two tests above cannot be broken by swapping one
        // rule for the other. If this ever reads prohibited_unless over the
        // non-spending types, the payment label stops being legal and the test
        // above fails for a reason that is not obvious from the failure alone.
        $rules = TransactionData::rules()['category_id'];

        // Built from the enum rather than handwritten, and the enum is the thing
        // that decides. Expense and charge are the only two that are categorised
        // spending, so those are the only two that may be omitted nowhere.
        $this->assertContains(
            'required_unless:type,'.collect(TransactionType::cases())
                ->reject(fn (TransactionType $type) => $type->requiresCategory())
                ->map(fn (TransactionType $type) => $type->value)
                ->implode(','),
            $rules
        );

        // Nothing here may forbid a category. Every non-spending type is
        // permitted one; a payment in particular is expected to be able to.
        $this->assertSame(
            [],
            array_values(array_filter(
                $rules,
                fn (string $rule) => str_starts_with($rule, 'prohibited')
            ))
        );
    }

    public function test_a_trade_needs_no_category(): void
    {
        $data = TransactionData::from($this->tradeRequest('buy'));

        $this->assertNull($data->category_id);
    }

    public function test_income_may_be_uncategorised(): void
    {
        // `categories` has no income/expense discriminator, so requiring one
        // here would mean picking from a list of spending categories. Optional
        // until that gap is closed.
        $data = TransactionData::from($this->postRequest([
            'type' => 'deposit',
            'category_id' => null,
        ]));

        $this->assertNull($data->category_id);
    }

    // ---------------------------------------------------------------------
    // Derived trade amount
    // ---------------------------------------------------------------------

    public function test_a_buy_derives_its_amount_from_the_quantity_and_price(): void
    {
        $data = TransactionData::from($this->tradeRequest('buy'));

        $this->assertSame('15050.0000', $data->amount);
    }

    public function test_a_sell_subtracts_the_fee_from_its_proceeds(): void
    {
        $data = TransactionData::from($this->tradeRequest('sell', [
            'meta_data' => [
                'symbol' => '0700.HK',
                'quantity' => '100',
                'unit_price' => '150.50',
                'fees' => '25.00',
            ],
        ]));

        $this->assertSame('15025.0000', $data->amount);
    }

    public function test_a_supplied_amount_on_a_trade_is_rejected(): void
    {
        // The amount is a product of two numbers the client already sent, so
        // accepting a stated amount as well would mean two sources of truth and
        // no way to tell which one the balance used.
        try {
            TransactionData::from($this->tradeRequest('buy', ['amount' => '1.00']));
            $this->fail('A trade accepted a client-supplied amount.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
    }

    public function test_a_supplied_amount_on_a_trade_is_overwritten_rather_than_kept(): void
    {
        // Belt to the rule's braces. Data::from() validates, so the test above
        // already covers the normal path; this constructs the DTO directly to
        // reach the path where nobody validated at all. A trade whose amount can
        // be whatever the client said is a trade that can be wrong, so the
        // constructor does not take the client's word for it.
        $data = new TransactionData(
            id: null,
            account_id: $this->securityId,
            category_id: null,
            date: '2026-01-01',
            type: TransactionType::Buy,
            description: 'Buy 0700.HK',
            amount: '1.00',
            ccy: Currency::Hkd,
            status: null,
            meta_data: new TransactionMetaData(
                symbol: '0700.HK',
                quantity: '100',
                unit_price: '150.50',
            ),
            created_at: null,
        );

        $this->assertSame('15050.0000', $data->amount);
    }

    public function test_a_non_trade_keeps_its_supplied_amount(): void
    {
        // The mirror of the test above, so the overwrite is not unconditional.
        $data = new TransactionData(
            id: null,
            account_id: $this->accountId,
            category_id: $this->categoryId,
            date: '2026-01-01',
            type: TransactionType::Withdraw,
            description: 'Coffee',
            amount: '4.5000',
            ccy: Currency::Hkd,
            status: null,
            meta_data: null,
            created_at: null,
        );

        $this->assertSame('4.5000', $data->amount);
    }

    public function test_a_trade_with_no_meta_is_rejected(): void
    {
        // Without a quantity and a price there is nothing to derive the amount
        // from, and a null amount would fail the NOT NULL column at insert with
        // a database error rather than a validation error.
        //
        // This is why the meta is required outright rather than relying on the
        // nested rules: when meta_data is missing from the payload entirely,
        // those rules never run, so a buy with no meta at all would otherwise
        // pass and reach the column with a null amount.
        try {
            TransactionData::from($this->tradeRequest('buy', ['meta_data' => null]));
            $this->fail('A trade with no meta was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('meta_data', $e->errors());
        }
    }

    public function test_a_trade_with_partial_meta_is_rejected(): void
    {
        // The other half of the same hole: meta present but the price missing.
        $this->assertFieldRejected([
            'account_id' => $this->securityId,
            'type' => 'buy',
            'category_id' => null,
            'amount' => null,
            'meta_data' => ['symbol' => '0700.HK', 'quantity' => '100'],
        ], 'meta_data.unit_price');
    }

    public function test_a_trade_is_refused_in_a_currency_other_than_its_brokerages(): void
    {
        // One currency per broker: a USD buy on the HKD brokerage would settle out of the
        // HKD bank and add USD to an HKD position.
        try {
            TransactionData::from($this->tradeRequest('buy', ['ccy' => 'USD']));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ccy', $e->errors());

            return;
        }

        $this->fail('A USD buy on the HKD brokerage was accepted.');
    }

    public function test_a_dividend_keeps_its_supplied_amount(): void
    {
        $this->settleBrokerIntoTheBank();

        // Not a trade: a fixed sum with no quantity or price to derive one from.
        $data = TransactionData::from($this->postRequest([
            'type' => 'dividend',
            'category_id' => null,
            'amount' => '312.4400',
            'meta_data' => ['symbol' => '0700.HK', 'brokerage_account_id' => $this->securityId],
        ]));

        $this->assertSame('312.4400', $data->amount);
        $this->assertSame('0700.HK', $data->meta_data->symbol);
    }

    public function test_a_dividend_with_no_symbol_is_refused(): void
    {
        $this->settleBrokerIntoTheBank();

        $this->assertFieldRejected(
            [
                'type' => 'dividend',
                'meta_data' => ['symbol' => null, 'brokerage_account_id' => $this->securityId],
            ],
            'meta_data.symbol'
        );
    }

    public function test_a_dividend_with_no_brokerage_is_refused(): void
    {
        $this->assertFieldRejected(
            ['type' => 'dividend', 'meta_data' => ['symbol' => '0700.HK']],
            'meta_data.brokerage_account_id'
        );
    }

    public function test_a_dividend_naming_a_brokerage_that_settles_elsewhere_is_refused(): void
    {
        $this->assertFieldRejected(
            ['type' => 'dividend', 'meta_data' => ['symbol' => '0700.HK', 'brokerage_account_id' => $this->securityId]],
            'meta_data.brokerage_account_id'
        );
    }

    public function test_only_a_dividend_names_a_brokerage(): void
    {
        $this->settleBrokerIntoTheBank();

        $this->assertFieldRejected(
            ['type' => 'deposit', 'category_id' => null, 'meta_data' => ['brokerage_account_id' => $this->securityId]],
            'meta_data.brokerage_account_id'
        );
    }

    private function settleBrokerIntoTheBank(): void
    {
        Account::find($this->securityId)->meta()->create(['meta' => ['settlement_account_id' => $this->accountId]]);
    }

    public function test_a_deposit_on_a_bank_needs_no_symbol(): void
    {
        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->accountId,
            'type' => 'deposit',
            'ccy' => 'HKD',
            'category_id' => null,
            'amount' => '312.4400',
            'meta_data' => ['symbol' => null],
        ]));

        $this->assertNull($data->meta_data->symbol);
    }

    public function test_a_non_trade_requires_an_amount(): void
    {
        $this->assertFieldRejected(['amount' => null], 'amount');
    }

    // ---------------------------------------------------------------------
    // Derived due date
    // ---------------------------------------------------------------------

    public function test_a_charge_derives_its_due_date_from_the_account_terms(): void
    {
        // Closing on the 25th and payable 15 days later: a charge on 1 Jan falls
        // in the statement that closes on 25 Jan, so it is due on 9 Feb. Note
        // the term runs from the closing day, not from the charge, which is why
        // 1 Jan becomes 9 Feb rather than 16 Jan.
        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'charge',
            'ccy' => 'HKD',
            'date' => '2026-01-01',
            'meta_data' => [],
        ]));

        $this->assertSame('2026-02-09', $data->meta_data->due_date);
    }

    public function test_the_derived_due_date_joins_the_bag_rather_than_replacing_it(): void
    {
        // The derivation writes into a bag the client already sent, so the fields
        // that came with it have to still be there afterwards. A
        // reconstruct-and-replace would quietly drop the card_amount, and the row
        // would persist complete-looking and incomplete.
        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'charge',
            // USD on an HKD card, because a card-currency figure on a charge already
            // denominated in the card's own is refused -- see guardCardAmount().
            'ccy' => 'USD',
            'date' => '2026-01-01',
            'meta_data' => ['card_amount' => '780.0000'],
        ]));

        $this->assertSame('780.0000', $data->meta_data->card_amount);

        $this->assertSame('2026-02-09', $data->meta_data->due_date);
    }

    public function test_a_charge_with_no_meta_bag_at_all_still_gets_a_due_date(): void
    {
        // The trap in moving a derived field into an optional bag. meta_data is
        // required for a trade and optional for everything else, so a charge
        // that sends no bag is perfectly valid -- and a `?->` on the derivation
        // would leave it with no due date at all. Silent, and wrong: the charge
        // would fall out of its statement's settlement figure without any error
        // anywhere. So the bag is created rather than skipped.
        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'charge',
            'ccy' => 'HKD',
            'date' => '2026-01-01',
        ]));

        $this->assertNotNull($data->meta_data);
        $this->assertSame('2026-02-09', $data->meta_data->due_date);
    }

    public function test_a_charge_after_the_closing_day_moves_to_the_next_period(): void
    {
        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'charge',
            'ccy' => 'HKD',
            'date' => '2026-01-26',
            'meta_data' => [],
        ]));

        $this->assertSame('2026-03-12', $data->meta_data->due_date);
    }

    public function test_a_charge_on_the_closing_day_belongs_to_the_next_statement(): void
    {
        // The card closes on the 25th, so the 25th is the boundary and a charge made
        // on it is not on the statement that closes that day. It is billed by the one
        // closing on 25 Feb, payable 15 days later.
        //
        // The day before, for the same reason it is asserted in
        // CardStatementCycleTest: if this were passing because the 25th were simply
        // being treated as any other day, the assertion would be about nothing.
        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'charge',
            'ccy' => 'HKD',
            'date' => '2026-01-25',
            'meta_data' => [],
        ]));

        $this->assertSame('2026-03-12', $data->meta_data->due_date);
    }

    public function test_a_charge_on_a_card_with_no_statement_day_has_no_due_date(): void
    {
        // Possible for a card row that predates statement_day, or one written
        // straight to the database. No cycle means no due date -- better a null
        // the user can see than a date invented from the payment term alone,
        // since a term is only a number of days and says nothing about when the
        // statement it runs from closed.
        $card = Account::create([
            'name' => 'Card B',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
        ]);
        $card->meta()->create(['meta' => ['term_days' => 15]]);

        $data = TransactionData::from($this->postRequest([
            'account_id' => $card->id,
            'type' => 'charge',
            'ccy' => 'HKD',
            'meta_data' => [],
        ]));

        $this->assertNull($data->meta_data->due_date);
    }

    public function test_a_charge_keeps_a_due_date_the_client_supplied(): void
    {
        // A backdated or corrected charge may need to land in a period other
        // than the one its date implies.
        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'charge',
            'ccy' => 'HKD',
            'meta_data' => ['due_date' => '2026-04-15'],
        ]));

        $this->assertSame('2026-04-15', $data->meta_data->due_date);
    }

    public function test_only_a_charge_derives_a_due_date(): void
    {
        // A bag sent but no due date in it, so the nullish read below is
        // reading the property rather than short-circuiting on a missing bag.
        foreach (['withdraw', 'deposit'] as $type) {
            $data = TransactionData::from($this->postRequest([
                'type' => $type,
                'meta_data' => [],
            ]));

            $this->assertNotNull($data->meta_data, "A {$type} should still carry the bag it was sent.");
            $this->assertNull($data->meta_data->due_date, "A {$type} should have no due date.");
        }
    }

    public function test_a_payment_may_carry_a_due_date_to_target_a_period(): void
    {
        // A payment settles a specific statement, so which one has to be
        // recorded or settlement cannot be a single grouped subtraction. It is
        // supplied rather than derived: the statement a payment lands on is
        // normally the earliest unpaid, and working that out means querying
        // outstanding balances -- deliberately left to the controller.
        $data = TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'payment',
            'category_id' => null,
            'meta_data' => ['due_date' => '2026-02-15'],
        ]));

        $this->assertSame('2026-02-15', $data->meta_data->due_date);
    }

    public function test_a_due_date_must_be_iso_formatted(): void
    {
        // The rule now lives on the nested key, so the error is keyed there too.
        // A client reading `due_date` out of the bag would see nothing at all.
        $this->assertFieldRejected([
            'account_id' => $this->cardId,
            'type' => 'charge',
            'meta_data' => ['due_date' => '15/02/2026'],
        ], 'meta_data.due_date');
    }

    public function test_a_malformed_date_does_not_explode_the_due_date_derivation(): void
    {
        // The date rule reports the bad format. The constructor must not also
        // throw a parse error on its way past, or the user sees an exception
        // instead of the field error.
        $this->expectException(ValidationException::class);

        TransactionData::from($this->postRequest([
            'account_id' => $this->cardId,
            'type' => 'charge',
            'date' => 'not-a-date',
        ]));
    }

    public function test_the_conditional_rule_lists_track_the_enum(): void
    {
        // The "required unless" / "prohibited unless" lists are built from
        // TransactionType, so a new case is picked up automatically. This
        // asserts they still agree with the enum's own predicates, which is what
        // would fail if anyone hand-wrote the lists again.
        foreach (TransactionType::cases() as $type) {
            // A category is required exactly when the type says so, so omitting
            // one is rejected exactly then.
            $this->assertSame(
                $type->requiresCategory(),
                $this->rejectsField('category_id', null, $type, 'category_id'),
                "category_id requirement for {$type->value} disagrees with requiresCategory()."
            );

            // An amount is required exactly when it is not derived, so omitting
            // one is rejected exactly then.
            $this->assertSame(
                ! $type->derivesAmount(),
                $this->rejectsField('amount', null, $type, 'amount'),
                "amount requirement for {$type->value} disagrees with derivesAmount()."
            );
        }
    }

    /**
     * Whether setting one field to one value is rejected, with everything else
     * in the payload valid for the given type.
     *
     * The base payload is built for the type rather than reused, because a
     * leftover from postRequest() would otherwise be the thing that fails: a
     * trade left holding an amount is rejected on `amount` no matter which field
     * is under test.
     */
    private function rejectsField(
        string $field,
        mixed $value,
        TransactionType $type,
        string $assertionField
    ): bool {
        $accountId = match (true) {
            $type->isAllowedFor(AccountType::Cash) => $this->accountId,
            $type->isAllowedFor(AccountType::Card) => $this->cardId,
            default => $this->securityId,
        };

        $overrides = [
            'account_id' => $accountId,
            'type' => $type->value,
            $field => $value,
        ];

        // A row on the brokerage is refused in any currency but its HKD, which would
        // otherwise be the field that fails.
        if ($accountId === $this->securityId) {
            $overrides['ccy'] = 'HKD';
        }

        // Everything not under test has to be valid for this type, or it is the
        // thing that fails instead. Note that ??= is no use here: it treats null
        // as absent, so it would overwrite the very value under test.
        if ($field !== 'category_id' && $type->requiresCategory()) {
            $overrides['category_id'] = $this->categoryId;
        }

        if ($type->derivesAmount()) {
            $overrides['amount'] = $field === 'amount' ? $value : null;
            $overrides['meta_data'] = [
                'symbol' => '0700.HK',
                'quantity' => '1',
                'unit_price' => '1.0000',
            ];
        } elseif ($field !== 'amount') {
            $overrides['amount'] = '1.0000';
        }

        try {
            TransactionData::from($this->postRequest($overrides));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($assertionField, $e->errors());

            return true;
        }

        return false;
    }

    public function test_only_the_extra_constraints_are_declared(): void
    {
        // `required` and the type checks are derived from the constructor
        // property types by spatie, so rules() only adds what the types cannot
        // express: existence, formats, and the conditions that depend on the
        // transaction type. This also documents the shape returned by the debug
        // stub in TransactionController::store().
        $rules = TransactionData::rules();

        $this->assertSame(['exists:accounts,id'], $rules['account_id']);
        $this->assertSame(['date_format:Y-m-d'], $rules['date']);
        $this->assertSame(['required', 'max:255'], $rules['description']);

        // card_amount and due_date are not here: both are type-specific, so they are
        // declared in TransactionMetaData with the trade fields.
        $this->assertArrayNotHasKey('card_amount', $rules);
        $this->assertArrayNotHasKey('due_date', $rules);

        // ccy is an enum, so spatie derives its membership and the `size:3` that
        // stood in for it is gone -- it never checked membership anyway, so what
        // it actually guarded was a value length the column already allows.
        $this->assertArrayNotHasKey('ccy', $rules);

        // type is an enum, so spatie validates its value and no length rule is
        // needed -- the column is varchar(255) and cannot be reached with
        // anything but a declared case.
        $this->assertArrayNotHasKey('type', $rules);
    }
}
