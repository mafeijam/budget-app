<?php

namespace Tests\Feature;

use App\DTO\AccountData;
use App\DTO\AccountMetaData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the link from a securities account to the cash account it settles into.
 *
 * A brokerage holds securities; the money to buy and sell them lives in a bank
 * account. A buy recorded against the securities account therefore says nothing
 * about any bank balance, and without a link there is no way to tell which bank
 * the money left or arrived in.
 *
 * The link lives in the account's meta bag, with the card terms, rather than in a
 * column. That is a deliberate reversal of an earlier decision and it costs two
 * things this file has to keep honest: the database can no longer refuse a delete
 * on its own, so AccountController::destroy() has to do it, and the link can no
 * longer be joined or indexed. Both are asserted here.
 *
 * The link is a pointer and nothing more. It does not write a second row on the
 * cash account, so the two sides of a trade are recorded separately and can drift
 * apart -- that is a deliberate trade for now, not an oversight, and the point
 * of these tests is to pin down what the link does and does not guarantee.
 *
 * The rule that does the most work is that the field is present exactly when the
 * account is a securities account, and points at a cash account. Those two
 * together make self-reference and settlement cycles structurally impossible
 * rather than something that has to be detected: a securities account is never
 * cash, so it cannot point at itself, and a cash account may not carry the field
 * at all, so there is no second hop for a cycle to close through.
 */
class SettlementAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    private int $cashId;

    protected function setUp(): void
    {
        parent::setUp();

        // Created straight on the model: AccountData requires a settlement
        // account for a securities account, so the cash account a test points at
        // has to exist before any DTO-built payload can name it.
        $this->cashId = Account::create([
            'name' => 'Bank',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'HKD',
        ])->id;
    }

    private function accountRequest(array $overrides = []): Request
    {
        return Request::create('/accounts', 'POST', array_merge([
            'name' => 'Broker',
            'status' => 'active',
            'type' => 'security',
            'ccy' => 'HKD',
            'meta_data' => ['settlement_account_id' => $this->cashId],
        ], $overrides));
    }

    /**
     * Write a settlement link directly, bypassing the DTO.
     *
     * Most tests need a brokerage that is already linked before they can build a
     * payload about it, and the DTO refuses to build a securities account without
     * one. Going through the meta relation keeps that in one place, so a change to
     * where the link lives is a one-line change rather than a sweep.
     */
    private function pointAt(Account $broker, int $targetId): void
    {
        $broker->meta()->updateOrCreate(
            ['id' => $broker->meta?->id],
            ['meta' => ['settlement_account_id' => $targetId]]
        );
    }

    /**
     * Asserts that a payload override is rejected on a specific field.
     *
     * The error bag is inspected rather than the exception type so an unrelated
     * rule firing first cannot mask the field under test. The path is the full
     * dotted one because that is the key the error bag is keyed by, and spelling
     * it out means a failure message points at the field the user would fix.
     */
    private function assertFieldRejected(array $overrides, string $field): void
    {
        try {
            AccountData::from($this->accountRequest($overrides));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return;
        }

        $this->fail("Expected [{$field}] to be rejected, but the payload validated.");
    }

    // ---------------------------------------------------------------------
    // Where the link lives
    // ---------------------------------------------------------------------

    public function test_the_link_is_not_a_column(): void
    {
        // The reversal, asserted rather than assumed. A column here would mean two
        // places for a type-specific attribute, and whichever the code forgot to
        // read would be the one silently holding the stale value.
        $columns = DB::select(
            "SELECT column_name AS col_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'accounts'"
        );

        $names = array_map(fn ($row) => $row->col_name, $columns);

        $this->assertNotContains('settlement_account_id', $names);
    }

    public function test_the_link_round_trips_through_the_meta_bag(): void
    {
        // The whole move in one assertion: what the DTO accepted is what the
        // meta row holds. A dropped key or a filter() that ate the id both fail
        // here, and neither is visible from the DTO alone.
        $data = AccountData::from($this->accountRequest());

        $broker = Account::create($data->except('meta_data')->toArray());
        $broker->meta()->create(['meta' => collect($data->meta_data->all())->filter()]);

        $this->assertSame(
            $this->cashId,
            (int) $broker->fresh()->meta->meta['settlement_account_id']
        );
    }

    // ---------------------------------------------------------------------
    // What the foreign key used to do, and what does it now
    // ---------------------------------------------------------------------

    public function test_deleting_a_bank_a_brokerage_settles_into_is_refused(): void
    {
        // The behaviour the foreign key used to buy, now bought by the
        // controller: tidy up a dormant cash account with a brokerage still
        // pointing at it and the delete must fail, rather than leaving a
        // securities account with no cash story.
        //
        // Named apart from setUp()'s 'Bank' because accounts.name is unique and
        // a collision fails the test for the wrong reason.
        $bank = Account::create(['name' => 'Settlement Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->pointAt($broker, $bank->id);

        $this->delete("/accounts/{$bank->id}")->assertSessionHas('message');

        $this->assertDatabaseHas('accounts', ['id' => $bank->id]);
        $this->assertDatabaseHas('accounts', ['id' => $broker->id]);
    }

    public function test_the_refusal_names_the_account_that_blocks_the_delete(): void
    {
        // Otherwise the user is left to work out which brokerage is in the way,
        // and the only other way to find out is to delete things until something
        // complains.
        $bank = Account::create(['name' => 'Named Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $broker = Account::create(['name' => 'Blocking Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->pointAt($broker, $bank->id);

        $response = $this->delete("/accounts/{$bank->id}");

        $response->assertSessionHas('message', fn (string $m) => str_contains($m, 'Named Bank')
            && str_contains($m, 'Blocking Broker'));
    }

    public function test_a_brokerage_does_not_block_its_own_deletion(): void
    {
        // The mirror, so the guard is not simply refusing everything. The
        // brokerage is the referring row here and the bank the referenced one, so
        // deleting the brokerage must be allowed even though it points somewhere.
        $bank = Account::create(['name' => 'Spare Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $broker = Account::create(['name' => 'Lone Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->pointAt($broker, $bank->id);

        $this->delete("/accounts/{$broker->id}")->assertSessionHas('message', 'Account [Lone Broker] deleted');

        $this->assertDatabaseMissing('accounts', ['id' => $broker->id]);
        $this->assertDatabaseHas('accounts', ['id' => $bank->id]);
    }

    public function test_the_refusal_survives_the_id_stored_as_a_string(): void
    {
        // The guard reaches into the JSON, and a JSON number is not the same
        // value as the string "12" when MySQL compares them. A form submits the
        // select's value as a string, so the unguarded form of this query matches
        // nothing and the delete goes through.
        $bank = Account::create(['name' => 'Stringly Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $broker = Account::create(['name' => 'Stringly Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);

        $broker->meta()->create(['meta' => ['settlement_account_id' => (string) $bank->id]]);

        $this->delete("/accounts/{$bank->id}");

        $this->assertDatabaseHas('accounts', ['id' => $bank->id]);
    }

    public function test_the_database_no_longer_refuses_the_delete_on_its_own(): void
    {
        // Stated rather than left implied, because it is the price of the move and
        // the next person to find a dangling link needs to know where to look.
        // Only AccountController::destroy() checks; anything else writing SQL
        // deletes the bank and leaves the brokerage pointing at nothing.
        $bank = Account::create(['name' => 'Unguarded Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $broker = Account::create(['name' => 'Unguarded Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->pointAt($broker, $bank->id);

        DB::table('accounts')->where('id', $bank->id)->delete();

        $this->assertDatabaseMissing('accounts', ['id' => $bank->id]);
        $this->assertNull($broker->fresh()->settlementAccount());
    }

    // ---------------------------------------------------------------------
    // The field is present exactly for a securities account
    // ---------------------------------------------------------------------

    public function test_a_securities_account_must_say_where_it_settles(): void
    {
        // Required, not optional. A brokerage with no bank account has no cash
        // story at all: a buy recorded against it says nothing about where the
        // money went, and there is nothing to group or report on. Failing at the
        // door is better than an account that saves cleanly and is silently
        // incomplete forever after.
        $this->assertFieldRejected(
            ['meta_data' => ['settlement_account_id' => null]],
            'meta_data.settlement_account_id'
        );
    }

    public function test_a_securities_account_is_accepted_with_a_cash_account(): void
    {
        $data = AccountData::from($this->accountRequest());

        $this->assertSame('security', $data->type->value);
        $this->assertSame($this->cashId, $data->meta_data->settlement_account_id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonSecurityTypeProvider(): array
    {
        return ['cash' => ['cash'], 'card' => ['card']];
    }

    #[DataProvider('nonSecurityTypeProvider')]
    public function test_no_other_account_type_may_carry_a_settlement_account(string $type): void
    {
        // This is the rule that makes cycles impossible, so it is worth being
        // strict about: a cash account pointing at a securities account is the
        // second hop a cycle would need, and closing it here means no cycle can
        // be constructed at all rather than needing to be detected.
        //
        // A card payload carries its statement terms too, so the field under test
        // is the only thing wrong with it -- otherwise term_days would be the
        // error and this would pass without the prohibition ever running.
        $meta = $type === 'card' ? ['term_days' => '15', 'statement_day' => 25] : [];
        $meta['settlement_account_id'] = $this->cashId;

        $this->assertFieldRejected([
            'name' => "Probe {$type}",
            'type' => $type,
            'meta_data' => $meta,
        ], 'meta_data.settlement_account_id');
    }

    #[DataProvider('nonSecurityTypeProvider')]
    public function test_no_other_account_type_needs_one_either(string $type): void
    {
        // The mirror, so the pair of rules is pinned as "present iff securities"
        // rather than "prohibited for cash" with nothing said about card.
        $meta = $type === 'card' ? ['term_days' => '15', 'statement_day' => 25] : [];
        $meta['settlement_account_id'] = null;

        $data = AccountData::from($this->accountRequest([
            'name' => "Probe {$type}",
            'type' => $type,
            'meta_data' => $meta,
        ]));

        $this->assertNull($data->meta_data->settlement_account_id);
    }

    // ---------------------------------------------------------------------
    // What the target may be
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonCashTypeProvider(): array
    {
        return ['card' => ['card'], 'security' => ['security']];
    }

    #[DataProvider('nonCashTypeProvider')]
    public function test_a_securities_account_must_settle_into_cash(string $targetType): void
    {
        // A brokerage settling into another brokerage is not a thing, and a card
        // is a liability rather than a place money sits. This needs the database
        // to check, so it is not a rule on the payload.
        $target = Account::create([
            'name' => "Target {$targetType}",
            'status' => 'active',
            'type' => $targetType,
            'ccy' => 'HKD',
        ]);

        if ($targetType === 'security') {
            $this->pointAt($target, $this->cashId);
        }

        try {
            AccountData::from($this->accountRequest([
                'meta_data' => ['settlement_account_id' => $target->id],
            ]));
            $this->fail("A securities account was allowed to settle into a {$targetType} account.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('meta_data.settlement_account_id', $e->errors());
            $this->assertStringContainsString('cash', $e->errors()['meta_data.settlement_account_id'][0]);
        }
    }

    public function test_the_cash_target_is_checked_even_without_validating(): void
    {
        // The target's type is only knowable from the database, so this cannot be
        // a rule. Doing it in the constructor means it holds whether or not the
        // caller remembered to call validate() -- the alternative is a client
        // that skips validation pointing a brokerage at a credit card.
        $card = Account::create([
            'name' => 'Target Card',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'HKD',
        ]);

        $this->expectException(ValidationException::class);

        new AccountData(
            id: null,
            name: 'Broker',
            status: AccountStatus::Active,
            type: AccountType::Security,
            ccy: Currency::Hkd,
            created_at: null,
            meta_data: new AccountMetaData(
                term_days: null,
                statement_day: null,
                settlement_account_id: $card->id,
            ),
        );
    }

    public function test_the_target_must_exist(): void
    {
        // Left to the constructor this would be a "not a cash account" message,
        // which points at the wrong problem. The exists rule reports the real
        // one, and it runs first.
        $this->assertFieldRejected(
            ['meta_data' => ['settlement_account_id' => 999999]],
            'meta_data.settlement_account_id'
        );
    }

    public function test_a_securities_account_may_not_settle_into_itself(): void
    {
        // Already impossible -- a securities account is not cash, so the cash
        // guard rejects it. The different rule is here anyway, and the reason is
        // the message rather than the coverage: without it the user is told the
        // target is not a cash account, which is true but does not say that they
        // pointed the account at itself.
        $broker = Account::create([
            'name' => 'Self Broker',
            'status' => 'active',
            'type' => 'security',
            'ccy' => 'HKD',
        ]);
        $this->pointAt($broker, $this->cashId);

        $this->assertFieldRejected([
            'id' => $broker->id,
            'meta_data' => ['settlement_account_id' => $broker->id],
        ], 'meta_data.settlement_account_id');
    }

    public function test_a_settlement_cycle_cannot_be_built(): void
    {
        // Two securities accounts pointing at each other. There is no cycle
        // detection anywhere, deliberately: closing the second hop -- the cash
        // account may not carry the field -- makes it unrepresentable instead.
        // A→B→A needs a cash account in the middle, and that is the case the
        // prohibition forbids, so this test would still pass if the prohibition
        // were ever loosened by accident.
        $one = Account::create(['name' => 'One', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->pointAt($one, $this->cashId);

        $two = Account::create(['name' => 'Two', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $this->pointAt($two, $one->id);

        // The hop that would close a cycle is the one a cash account is refused.
        $this->assertFieldRejected([
            'name' => 'Loop',
            'type' => 'cash',
            'meta_data' => ['settlement_account_id' => $two->id],
        ], 'meta_data.settlement_account_id');

        $this->assertNotSame($two->id, (int) $one->fresh()->meta->meta['settlement_account_id']);
    }

    public function test_an_inactive_cash_account_may_still_be_settled_into(): void
    {
        // Status and settlement are orthogonal. A closed account still holds the
        // history, and a dividend arriving from a broker you have since stopped
        // using is a real thing to have to record. Blocking inactive targets
        // would make historical rows unattachable.
        $closed = Account::create([
            'name' => 'Closed Bank',
            'status' => 'inactive',
            'type' => 'cash',
            'ccy' => 'HKD',
        ]);

        $data = AccountData::from($this->accountRequest([
            'meta_data' => ['settlement_account_id' => $closed->id],
        ]));

        $this->assertSame($closed->id, $data->meta_data->settlement_account_id);
    }

    // ---------------------------------------------------------------------
    // Traversal
    // ---------------------------------------------------------------------

    public function test_the_link_can_be_traversed(): void
    {
        // A link nothing can follow is half-built. This is a method rather than a
        // belongsTo, because Eloquent cannot join on a JSON path -- so this test
        // is also the thing that would fail if the accessor were dropped.
        $broker = Account::create([
            'name' => 'Traversed',
            'status' => 'active',
            'type' => 'security',
            'ccy' => 'HKD',
        ]);
        $this->pointAt($broker, $this->cashId);

        $this->assertInstanceOf(Account::class, $broker->fresh()->settlementAccount());
        $this->assertSame($this->cashId, $broker->fresh()->settlementAccount()->id);
    }

    public function test_an_account_with_no_settlement_account_traverses_to_null(): void
    {
        $cash = Account::find($this->cashId);

        $this->assertNull($cash->settlementAccount());
    }

    // ---------------------------------------------------------------------
    // Currency parity
    // ---------------------------------------------------------------------

    public function test_a_brokerage_cannot_settle_into_a_bank_in_another_currency(): void
    {
        // The pairing is refused rather than converted. Converting would need a
        // rate at a moment neither account can see, and then a second conversion
        // on the way back with the proceeds; a transaction's fx_rate exists and is
        // wired to nothing, so the honest answer is that this cannot be recorded
        // rather than a guess at what it means.
        $yen = Account::create([
            'name' => 'Yen Bank',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'JPY',
        ]);

        $this->assertFieldRejected(
            ['meta_data' => ['settlement_account_id' => $yen->id]],
            'meta_data.settlement_account_id'
        );
    }

    public function test_the_mismatch_message_names_both_currencies(): void
    {
        // There are two accounts to change and the message should not send the
        // user off to work out which one is wrong. Spelled out here because the
        // phrasing is a product decision: naming only the brokerage's currency
        // reads as "this brokerage is the problem", which is not necessarily so.
        $usd = Account::create([
            'name' => 'Dollar Bank',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'USD',
        ]);

        try {
            AccountData::from($this->accountRequest([
                'ccy' => 'HKD',
                'meta_data' => ['settlement_account_id' => $usd->id],
            ]));
        } catch (ValidationException $e) {
            $this->assertSame(
                ['A HKD brokerage cannot settle into a USD account.'],
                $e->errors()['meta_data.settlement_account_id']
            );

            return;
        }

        $this->fail('Expected a currency mismatch to be rejected, but the payload validated.');
    }

    public function test_every_currency_settles_into_its_own(): void
    {
        // All six, not a sample, so trimming the enum cannot quietly strand a
        // currency a user has an account in. Each gets its own bank because
        // accounts.name is unique and a shared one would fail for the wrong
        // reason.
        foreach (Currency::cases() as $currency) {
            $bank = Account::create([
                'name' => "Bank {$currency->value}",
                'status' => 'active',
                'type' => 'cash',
                'ccy' => $currency->value,
            ]);

            $data = AccountData::from($this->accountRequest([
                'ccy' => $currency->value,
                'meta_data' => ['settlement_account_id' => $bank->id],
            ]));

            $this->assertSame($currency, $data->ccy, "{$currency->value} would not settle into its own bank");
            $this->assertSame($bank->id, $data->meta_data->settlement_account_id);
        }
    }

    public function test_the_currency_mismatch_is_checked_even_without_validating(): void
    {
        // Same reasoning as the cash-type check beside it: the target's currency
        // is only knowable from the database, so a client that skips validation
        // must not be able to record a pairing the validated path refuses.
        $yen = Account::create([
            'name' => 'Yen Bank',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'JPY',
        ]);

        $this->expectException(ValidationException::class);

        new AccountData(
            id: null,
            name: 'Broker',
            status: AccountStatus::Active,
            type: AccountType::Security,
            ccy: Currency::Hkd,
            created_at: null,
            meta_data: new AccountMetaData(
                term_days: null,
                statement_day: null,
                settlement_account_id: $yen->id,
            ),
        );
    }

    public function test_a_cash_account_outranks_a_currency_mismatch_in_the_message(): void
    {
        // Order is load-bearing, and this pins it. A card account in another
        // currency is both the wrong type and the wrong currency, and naming the
        // currency would imply the pairing could be fixed by converting. It
        // cannot: a card account is not somewhere a brokerage settles at all, so
        // the type is the more fundamental problem and the one to report.
        $card = Account::create([
            'name' => 'Yen Card',
            'status' => 'active',
            'type' => 'card',
            'ccy' => 'JPY',
        ]);

        try {
            AccountData::from($this->accountRequest([
                'ccy' => 'HKD',
                'meta_data' => ['settlement_account_id' => $card->id],
            ]));
        } catch (ValidationException $e) {
            $this->assertSame(
                ['A securities account settles into a cash account, not a card account.'],
                $e->errors()['meta_data.settlement_account_id']
            );

            return;
        }

        $this->fail('Expected a card target to be rejected, but the payload validated.');
    }

    public function test_matching_the_bank_currency_is_what_unblocks_the_pairing(): void
    {
        // The message says two accounts can change; this proves the other one is
        // reachable, so the refusal is a question and not a dead end. A guard
        // that also refused the corrected payload would leave the user with a
        // brokerage they cannot save at all.
        $yen = Account::create([
            'name' => 'Yen Bank',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'JPY',
        ]);

        $data = AccountData::from($this->accountRequest([
            'ccy' => 'JPY',
            'meta_data' => ['settlement_account_id' => $yen->id],
        ]));

        $this->assertSame(Currency::Jpy, $data->ccy);
        $this->assertSame($yen->id, $data->meta_data->settlement_account_id);
    }
}
