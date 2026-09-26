<?php

namespace Tests\Feature;

use App\DTO\AccountData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
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
            "SELECT index_name AS idx_name, column_name AS col_name, seq_in_index AS col_seq
               FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = ?
              ORDER BY index_name, seq_in_index",
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

    public function test_the_settlement_column_has_no_foreign_key(): void
    {
        // Stated so the omission is a decision on the record rather than an
        // oversight. `accounts` carries no foreign keys at all -- the original
        // migration used foreignIdFor() without constrained() -- and adding one
        // here alone would change what happens when an account is deleted. The
        // `exists:` rule is what rejects an orphan id in the meantime.
        $count = DB::selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.table_constraints
              WHERE table_schema = DATABASE() AND table_name = 'accounts'
                AND constraint_type = 'FOREIGN KEY'"
        );

        $this->assertSame(0, (int) $count->c);
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
        $meta = $type === 'card' ? ['due' => '15', 'statement_day' => 25] : [];

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
        $meta = $type === 'card' ? ['due' => '15', 'statement_day' => 25] : [];

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
            ccy: 'HKD',
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
}
