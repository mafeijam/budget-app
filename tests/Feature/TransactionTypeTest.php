<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the pairing between a transaction type and the account type it is
 * legal on.
 *
 * The transactions table is shared by cash, credit card and stock trading rows,
 * so "which values are allowed" is a property of a *pair*, not of the
 * transaction type alone. That makes the mapping easy to get subtly wrong: a
 * `payment` on a cash account, or a `sell` on a credit card, both look like
 * reasonable rows until you ask what they mean.
 *
 * accountTypes() is a `match` with no default arm, so adding a case without
 * extending it throws UnhandledMatchError rather than silently returning
 * nothing. The first test calls it on every case to keep that guarantee.
 */
class TransactionTypeTest extends TestCase
{
    public function test_every_type_resolves_to_at_least_one_account_type(): void
    {
        foreach (TransactionType::cases() as $type) {
            $accountTypes = $type->accountTypes();

            $this->assertNotEmpty(
                $accountTypes,
                TransactionType::class."{$type->name} maps to no account type."
            );
        }
    }

    public function test_every_account_type_is_reachable_from_some_transaction_type(): void
    {
        $covered = [];

        foreach (TransactionType::cases() as $type) {
            foreach ($type->accountTypes() as $accountType) {
                $covered[$accountType->value] = true;
            }
        }

        // Guards the other direction: adding an AccountType (a loan account,
        // say) without adding matching transaction types would otherwise leave
        // that account type unable to record anything at all.
        foreach (AccountType::cases() as $accountType) {
            $this->assertArrayHasKey(
                $accountType->value,
                $covered,
                "Account type {$accountType->value} has no transaction type that accepts it."
            );
        }
    }

    /**
     * @return array<string, array{0: TransactionType, 1: AccountType, 2: bool}>
     */
    public static function pairingProvider(): array
    {
        $cases = [];

        foreach (TransactionType::cases() as $type) {
            foreach (AccountType::cases() as $accountType) {
                $cases["{$type->value} on {$accountType->value}"] = [$type, $accountType];
            }
        }

        return $cases;
    }

    #[DataProvider('pairingProvider')]
    public function test_is_allowed_for_agrees_with_account_types(
        TransactionType $type,
        AccountType $accountType
    ): void {
        $this->assertSame(
            in_array($accountType, $type->accountTypes(), true),
            $type->isAllowedFor($accountType)
        );
    }

    public function test_a_payment_is_only_legal_on_a_credit_card(): void
    {
        // The single most load-bearing pairing in the design: a payment is what
        // settles a statement, which only a card has.
        $this->assertTrue(TransactionType::Payment->isAllowedFor(AccountType::Card));
        $this->assertFalse(TransactionType::Payment->isAllowedFor(AccountType::Cash));
        $this->assertFalse(TransactionType::Payment->isAllowedFor(AccountType::Security));
    }

    public function test_only_the_two_trades_derive_their_amount(): void
    {
        foreach (TransactionType::cases() as $type) {
            $this->assertSame(
                in_array($type, [TransactionType::Buy, TransactionType::Sell], true),
                $type->derivesAmount(),
                "{$type->value} derivesAmount() disagrees with the buy/sell set."
            );
        }
    }

    public function test_a_dividend_is_income_rather_than_a_trade(): void
    {
        // A dividend arrives as a fixed cash amount with no quantity or unit
        // price, so it must stay client-supplied even though it lives on a
        // securities account alongside the trades.
        $this->assertTrue(TransactionType::Dividend->isAllowedFor(AccountType::Security));
        $this->assertFalse(TransactionType::Dividend->derivesAmount());
    }

    public function test_only_expenses_and_charges_require_a_category(): void
    {
        $this->assertTrue(TransactionType::Expense->requiresCategory());
        $this->assertTrue(TransactionType::Charge->requiresCategory());

        foreach ([
            TransactionType::Income,
            TransactionType::Payment,
            TransactionType::Buy,
            TransactionType::Sell,
            TransactionType::Dividend,
        ] as $type) {
            $this->assertFalse(
                $type->requiresCategory(),
                "{$type->value} should not require a category."
            );
        }
    }

    public function test_a_pending_row_does_not_count_toward_a_balance(): void
    {
        // A pending card charge has not been billed yet, so including it would
        // overstate what is owed.
        $this->assertFalse(TransactionStatus::Pending->countsTowardBalance());
        $this->assertTrue(TransactionStatus::Posted->countsTowardBalance());
        $this->assertTrue(TransactionStatus::Settled->countsTowardBalance());
    }
}
