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
        $this->assertTrue(TransactionType::Deposit->isAllowedFor(AccountType::Security));
        $this->assertFalse(TransactionType::Deposit->derivesAmount());
    }

    public function test_a_cash_side_is_a_trade_or_a_dividend_on_a_brokerage(): void
    {
        // needsCashSide() is what decides whether a row is written beside another one, and
        // it is the same answer the form is sent for which fields to show. Asserted across
        // every type and every account type so a case added to the enum cannot arrive
        // without a decision here -- and derived from the two named rules rather than
        // restated, so the two methods cannot pass this and disagree with each other.
        foreach (TransactionType::cases() as $type) {
            foreach (AccountType::cases() as $accountType) {
                $expected = $type->derivesAmount() || $type->isDividend($accountType);

                $this->assertSame(
                    $expected,
                    $type->needsCashSide($accountType),
                    "{$type->value} on a {$accountType->value} account disagrees with the "
                        .'trades-and-dividends rule.'
                );
            }
        }

        // And the three that answer yes, by name, so the assertion above is not the only
        // thing holding the shape.
        $this->assertSame(
            ['buy', 'sell', 'deposit'],
            array_values(array_map(
                fn (TransactionType $type) => $type->value,
                array_filter(
                    TransactionType::cases(),
                    fn (TransactionType $type) => $type->needsCashSide(AccountType::Security)
                )
            ))
        );

        // A deposit on a bank is the same type and has no cash side: the account is what
        // decides, which is why this takes one.
        $this->assertFalse(TransactionType::Deposit->needsCashSide(AccountType::Cash));
    }

    public function test_only_a_deposit_on_a_brokerage_is_a_dividend(): void
    {
        // One case, and the reason this is a method rather than a comparison at each of its
        // three callers: every one of them reached for `type === Deposit` to choose the noun
        // in a message, and every one of them would have been wrong the same way -- a bank
        // deposit is money arriving and not a dividend, and so is a dividend's own cash side.
        foreach (TransactionType::cases() as $type) {
            foreach (AccountType::cases() as $accountType) {
                $this->assertSame(
                    $type === TransactionType::Deposit && $accountType === AccountType::Security,
                    $type->isDividend($accountType),
                    "{$type->value} on a {$accountType->value} account disagrees with the "
                        .'dividend rule.'
                );
            }
        }

        // A card has no deposit at all, so it cannot be asked; a cash one is the near miss.
        $this->assertFalse(TransactionType::Deposit->isDividend(AccountType::Cash));
        $this->assertFalse(TransactionType::Buy->isDividend(AccountType::Security));
    }

    public function test_only_a_charge_requires_a_category(): void
    {
        $this->assertTrue(TransactionType::Charge->requiresCategory());

        // A withdrawal is in this list because the app writes one itself beside every buy
        // and every card payment, and a required category would refuse the edit of a row
        // the user never entered. See requiresCategory().
        foreach ([
            TransactionType::Withdraw,
            TransactionType::Deposit,
            TransactionType::Payment,
            TransactionType::Buy,
            TransactionType::Sell,
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
    }
}
