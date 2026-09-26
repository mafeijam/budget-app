<?php

namespace Tests\Feature;

use App\DTO\AccountData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use Illuminate\Database\QueryException;
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
            'settlement_account_id' => $this->cashId,
        ], $overrides));
    }

    /**
     * Asserts that a payload override is rejected on a specific field.
     *
     * The error bag is inspected rather than the exception type so an unrelated
     * rule firing first cannot mask the field under test.
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
    // Schema
    // ---------------------------------------------------------------------

    /**
     * Every column is aliased explicitly. information_schema reports its column
     * names upper-case, and whether the driver hands them back upper- or
     * lower-case depends on PDO::ATTR_CASE, so the aliases pin it down.
     *
     * column_type rather than data_type, because "is it unsigned" is only
     * visible in the former -- both a signed and an unsigned 64-bit id report
     * data_type `bigint`.
     */
    private function columns(): array
    {
        $rows = DB::select(
            "SELECT column_name AS col_name, data_type AS col_type,
                    column_type AS col_full_type, is_nullable AS col_nullable
               FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'accounts'"
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row->col_name] = $row;
        }

        return $columns;
    }

    public function test_the_settlement_column_exists_and_may_be_empty(): void
    {
        // Nullable because most accounts are not securities accounts, and
        // because a cash or card account must not carry it at all.
        $columns = $this->columns();

        $this->assertArrayHasKey(
            'settlement_account_id',
            $columns,
            'accounts.settlement_account_id is missing; found: '.implode(', ', array_keys($columns))
        );

        $this->assertSame('YES', $columns['settlement_account_id']->col_nullable);
    }

    /**
     * The indexed columns of a table, from information_schema.statistics.
     *
     * Aliases are explicit for the same reason as in columns(): the driver may
     * hand back upper- or lower-case names depending on PDO::ATTR_CASE.
     */
    private function indexes(string $table): array
    {
        $rows = DB::select(
            'SELECT index_name AS idx_name, column_name AS col_name, seq_in_index AS col_seq
               FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ?
              ORDER BY index_name, seq_in_index',
            [$table]
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->idx_name][] = $row->col_name;
        }

        return $indexes;
    }

    public function test_the_settlement_column_is_an_unsigned_id(): void
    {
        $this->assertSame(
            'bigint unsigned',
            $this->columns()['settlement_account_id']->col_full_type
        );
    }

    public function test_the_settlement_column_is_indexed(): void
    {
        // Not decoration: the next migration's foreign key needs it, and so does
        // "which brokerages settle into this bank", which is the question a cash
        // account delete has to answer before it may proceed.
        $indexes = $this->indexes('accounts');
        $indexed = array_merge(...array_values($indexes));

        $this->assertContains('settlement_account_id', $indexed);
    }

    public function test_the_settlement_column_is_bound_to_the_accounts_table(): void
    {
        // Self-referential and restrict-on-delete, both deliberate. Cascade
        // would delete a user's securities account because they tidied up a
        // dormant bank account; set null would manufacture the exact invalid row
        // that prohibited_unless exists to prevent. Failing the delete is the
        // honest outcome, and this asserts it at the database level.
        $row = DB::selectOne(
            "SELECT k.column_name AS col_name, k.referenced_table_name AS ref_table,
                    r.delete_rule AS on_delete
               FROM information_schema.key_column_usage AS k
               JOIN information_schema.referential_constraints AS r
                 ON r.constraint_schema = k.constraint_schema
                AND r.constraint_name = k.constraint_name
              WHERE k.table_schema = DATABASE() AND k.table_name = 'accounts'
                AND k.column_name = 'settlement_account_id'"
        );

        $this->assertNotNull($row, 'accounts.settlement_account_id has no foreign key.');
        $this->assertSame('accounts', $row->ref_table);
        $this->assertSame('RESTRICT', $row->on_delete);
    }

    public function test_deleting_a_bank_a_brokerage_settles_into_is_refused(): void
    {
        // The behaviour that rule buys. Tidy up a dormant cash account with a
        // brokerage still pointing at it and the delete must fail, rather than
        // leaving a securities account with no cash story or taking the
        // brokerage down with it.
        //
        // Named apart from setUp()'s 'Bank' because accounts.name is unique and
        // a collision fails the test for the wrong reason.
        $bank = Account::create(['name' => 'Settlement Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $broker = Account::create(['name' => 'Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->update(['settlement_account_id' => $bank->id]);

        $refused = false;
        try {
            DB::table('accounts')->where('id', $bank->id)->delete();
        } catch (QueryException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'The bank was deleted while a brokerage still settled into it.');
        $this->assertSame($bank->id, $broker->fresh()->settlement_account_id);
    }

    public function test_a_brokerage_does_not_block_its_own_deletion(): void
    {
        // The mirror, so the constraint is not simply refusing everything. The
        // brokerage is the referring row here and the bank the referenced one,
        // so deleting the brokerage must be allowed even though it points
        // somewhere.
        $bank = Account::create(['name' => 'Spare Bank', 'status' => 'active', 'type' => 'cash', 'ccy' => 'HKD']);
        $broker = Account::create(['name' => 'Lone Broker', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $broker->update(['settlement_account_id' => $bank->id]);

        DB::table('accounts')->where('id', $broker->id)->delete();

        $this->assertDatabaseMissing('accounts', ['id' => $broker->id]);
        $this->assertDatabaseHas('accounts', ['id' => $bank->id]);
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
        $this->assertFieldRejected(['settlement_account_id' => null], 'settlement_account_id');
    }

    public function test_a_securities_account_is_accepted_with_a_cash_account(): void
    {
        $data = AccountData::from($this->accountRequest());

        $this->assertSame('security', $data->type->value);
        $this->assertSame($this->cashId, $data->settlement_account_id);
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
        $meta = $type === 'card' ? ['term_days' => '15', 'statement_day' => 25] : [];

        $this->assertFieldRejected([
            'name' => "Probe {$type}",
            'type' => $type,
            'meta_data' => $meta,
            'settlement_account_id' => $this->cashId,
        ], 'settlement_account_id');
    }

    #[DataProvider('nonSecurityTypeProvider')]
    public function test_no_other_account_type_needs_one_either(string $type): void
    {
        // The mirror, so the pair of rules is pinned as "present iff securities"
        // rather than "prohibited for cash" with nothing said about card.
        $meta = $type === 'card' ? ['term_days' => '15', 'statement_day' => 25] : [];

        $data = AccountData::from($this->accountRequest([
            'name' => "Probe {$type}",
            'type' => $type,
            'meta_data' => $meta,
            'settlement_account_id' => null,
        ]));

        $this->assertNull($data->settlement_account_id);
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
            $target->update(['settlement_account_id' => $this->cashId]);
        }

        try {
            AccountData::from($this->accountRequest(['settlement_account_id' => $target->id]));
            $this->fail("A securities account was allowed to settle into a {$targetType} account.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('settlement_account_id', $e->errors());
            $this->assertStringContainsString('cash', $e->errors()['settlement_account_id'][0]);
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
            meta_data: null,
            settlement_account_id: $card->id,
        );
    }

    public function test_the_target_must_exist(): void
    {
        // Left to the constructor this would be a "not a cash account" message,
        // which points at the wrong problem. The exists rule reports the real
        // one, and it runs first.
        $this->assertFieldRejected(['settlement_account_id' => 999999], 'settlement_account_id');
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
        $broker->update(['settlement_account_id' => $this->cashId]);

        $this->assertFieldRejected([
            'id' => $broker->id,
            'settlement_account_id' => $broker->id,
        ], 'settlement_account_id');
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
        $one->update(['settlement_account_id' => $this->cashId]);

        $two = Account::create(['name' => 'Two', 'status' => 'active', 'type' => 'security', 'ccy' => 'HKD']);
        $two->update(['settlement_account_id' => $one->id]);

        // The hop that would close a cycle is the one a cash account is refused.
        $this->assertFieldRejected([
            'name' => 'Loop',
            'type' => 'cash',
            'settlement_account_id' => $two->id,
        ], 'settlement_account_id');

        $this->assertNotSame($one->fresh()->settlement_account_id, $two->id);
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

        $data = AccountData::from($this->accountRequest(['settlement_account_id' => $closed->id]));

        $this->assertSame($closed->id, $data->settlement_account_id);
    }

    // ---------------------------------------------------------------------
    // Traversal
    // ---------------------------------------------------------------------

    public function test_the_link_can_be_traversed(): void
    {
        // A column nothing can follow is half-built. The relation is what lets
        // settlement queries and the account form's picker read the target
        // without every call site repeating the join by hand.
        $broker = Account::create([
            'name' => 'Traversed',
            'status' => 'active',
            'type' => 'security',
            'ccy' => 'HKD',
        ]);
        $broker->update(['settlement_account_id' => $this->cashId]);

        $this->assertInstanceOf(Account::class, $broker->fresh()->settlementAccount);
        $this->assertSame($this->cashId, $broker->fresh()->settlementAccount->id);
    }

    public function test_an_account_with_no_settlement_account_traverses_to_null(): void
    {
        $cash = Account::find($this->cashId);

        $this->assertNull($cash->settlementAccount);
    }

    // ---------------------------------------------------------------------
    // Currency parity
    // ---------------------------------------------------------------------

    public function test_a_brokerage_cannot_settle_into_a_bank_in_another_currency(): void
    {
        // The pairing is refused rather than converted. Converting would need a
        // rate at a moment neither account can see, and then a second conversion
        // on the way back with the proceeds; transactions.fx_rate exists and is
        // wired to nothing, so the honest answer is that this cannot be recorded
        // rather than a guess at what it means.
        $yen = Account::create([
            'name' => 'Yen Bank',
            'status' => 'active',
            'type' => 'cash',
            'ccy' => 'JPY',
        ]);

        $this->assertFieldRejected(['settlement_account_id' => $yen->id], 'settlement_account_id');
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
                'settlement_account_id' => $usd->id,
            ]));
        } catch (ValidationException $e) {
            $this->assertSame(
                ['A HKD brokerage cannot settle into a USD account.'],
                $e->errors()['settlement_account_id']
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
                'settlement_account_id' => $bank->id,
            ]));

            $this->assertSame($currency, $data->ccy, "{$currency->value} would not settle into its own bank");
            $this->assertSame($bank->id, $data->settlement_account_id);
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
            meta_data: null,
            settlement_account_id: $yen->id,
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
                'settlement_account_id' => $card->id,
            ]));
        } catch (ValidationException $e) {
            $this->assertSame(
                ['A securities account settles into a cash account, not a card account.'],
                $e->errors()['settlement_account_id']
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
            'settlement_account_id' => $yen->id,
        ]));

        $this->assertSame(Currency::Jpy, $data->ccy);
        $this->assertSame($yen->id, $data->settlement_account_id);
    }
}
