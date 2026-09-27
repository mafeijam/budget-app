<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\AccountBalance;
use App\Support\CardStatement;
use App\Support\CardStatementCycle;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the account table's Balance column says, and what it refuses to say.
 *
 * The arithmetic is a signed sum over every row of one account, and `amount` is a
 * positive magnitude, so the sign has to come from somewhere. It comes from
 * TransactionType::movesBalanceOn(), which takes the account type as a parameter
 * because the two together decide the direction and neither does alone: an expense
 * is money leaving a bank, and a charge is a debt the user has taken on, so both are
 * negative -- a charge and a payment on a bank being opposites here where physically
 * they are alike. A card that owes reads negative, and one paid beyond its charges
 * reads positive, because the balance is a position rather than a direction of travel.
 *
 * The two questions that are not arithmetic are the interesting ones. A securities
 * account holds positions rather than money, so it reports no balance at all rather
 * than a total of trades that would read as money and be nothing of the kind. And a
 * charge in a currency the card is not contributes the figure the user stated in the
 * card's own currency, because a card table adding USD to an HKD total would be wrong
 * in a way no assertion about the arithmetic would catch.
 */
class AccountBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $card;

    private Account $brokerage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bank = $this->account('Bank', 'cash');
        $this->card = $this->account('Card', 'card', ['term_days' => 15, 'statement_day' => 25]);
        $this->brokerage = $this->account('Brokerage', 'security');
    }

    // ---------------------------------------------------------------------
    // Which accounts have a balance at all
    // ---------------------------------------------------------------------

    public function test_a_cash_and_a_card_account_have_a_balance_and_a_brokerage_does_not(): void
    {
        $balances = AccountBalance::forAccounts(collect([$this->bank, $this->card, $this->brokerage]));

        $this->assertArrayHasKey($this->bank->id, $balances);
        $this->assertArrayHasKey($this->card->id, $balances);
        $this->assertArrayNotHasKey($this->brokerage->id, $balances);
    }

    public function test_a_brokerage_is_asked_about_rather_than_defaulted_to(): void
    {
        $this->assertTrue(AccountType::Cash->hasBalance());
        $this->assertTrue(AccountType::Card->hasBalance());
        $this->assertFalse(AccountType::Security->hasBalance());
    }

    public function test_an_account_with_no_transactions_is_zero_rather_than_absent(): void
    {
        // Blank and 0.0000 are different claims. Blank would say the figure was not
        // computed; a bank with nothing in it holds nothing, which is an answer.
        $this->assertSame('0.0000', $this->balanceOf($this->bank));
    }

    public function test_no_accounts_means_no_figure_at_all(): void
    {
        $this->assertSame([], AccountBalance::forAccounts(collect()));
    }

    public function test_a_trade_recorded_on_a_brokerage_leaves_no_balance_behind(): void
    {
        // The reason hasBalance() says no. The cash side of a trade is recorded against
        // the bank the brokerage settles through, so summing the trades alone would
        // produce a figure that looks like money and is not.
        $this->row($this->brokerage, 'buy', '500.0000');
        $this->row($this->brokerage, 'sell', '200.0000');

        $this->assertArrayNotHasKey(
            $this->brokerage->id,
            AccountBalance::forAccounts(collect([$this->brokerage]))
        );
    }

    // ---------------------------------------------------------------------
    // A bank
    // ---------------------------------------------------------------------

    public function test_income_raises_a_bank_and_an_expense_lowers_it(): void
    {
        $this->row($this->bank, 'income', '500.0000');
        $this->row($this->bank, 'expense', '120.5000');

        $this->assertSame('379.5000', $this->balanceOf($this->bank));
    }

    public function test_a_transfer_is_money_leaving_the_bank(): void
    {
        // The whole point of a transfer: money going out to a far side this app does
        // not track, which is why the card half of a card payment is a Payment and not
        // a second transfer.
        $this->row($this->bank, 'income', '1000.0000');
        $this->row($this->bank, 'transfer', '250.0000');

        $this->assertSame('750.0000', $this->balanceOf($this->bank));
    }

    public function test_a_bank_that_spent_more_than_it_received_reads_negative(): void
    {
        $this->row($this->bank, 'income', '40.0000');
        $this->row($this->bank, 'expense', '120.0000');

        $this->assertSame('-80.0000', $this->balanceOf($this->bank));
    }

    public function test_a_pending_row_does_not_move_a_balance(): void
    {
        $this->row($this->bank, 'income', '500.0000');
        $this->row($this->bank, 'expense', '40.0000', status: 'pending');

        $this->assertSame('500.0000', $this->balanceOf($this->bank));
    }

    public function test_a_settled_row_still_counts(): void
    {
        // Pending is the only state that does not, so settled and posted are both in
        // -- a trade settles days after it is written and its money has moved.
        $this->row($this->bank, 'expense', '75.0000', status: 'settled');

        $this->assertSame('-75.0000', $this->balanceOf($this->bank));
    }

    // ---------------------------------------------------------------------
    // A card
    // ---------------------------------------------------------------------

    public function test_an_unpaid_card_reads_negative(): void
    {
        // Negative because the user is down what they have spent and not yet paid,
        // not because the money left an account they can see. It did not; it is on
        // the card's statement.
        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-01-20', '80.5000');

        $this->assertSame('-200.5000', $this->balanceOf($this->card));
    }

    public function test_a_payment_clears_what_a_charge_owed(): void
    {
        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-01-20', '80.5000');
        $this->row($this->card, 'payment', '200.5000', ['due_date' => '2026-02-09']);

        $this->assertSame('0.0000', $this->balanceOf($this->card));
    }

    public function test_a_card_charge_is_counted_in_the_cards_own_currency(): void
    {
        // A USD 100 charge on an HKD card contributes what the user said it came to in
        // HKD, which is the figure the statement sums. Summing the raw amount would
        // report this card as owing 100 when it owes 780.
        $this->charge('2026-01-01', '100.0000', ['card_amount' => '780.0000']);

        $this->assertSame('-780.0000', $this->balanceOf($this->card));
    }

    public function test_a_charge_with_no_bag_is_still_owed(): void
    {
        // A charge on a card with no statement day has no bag, so the meta join finds
        // nothing for it. An inner join would drop the charge from the total and the
        // card would read as owing nothing, with nothing reporting the omission.
        $bare = $this->account('Bare Card', 'card');
        $this->chargeOn($bare, '2026-01-01', '90.0000', bag: []);

        $this->assertSame('-90.0000', $this->balanceOf($bare));
    }

    public function test_a_pending_charge_is_not_owed_yet(): void
    {
        $this->charge('2026-01-01', '120.0000', status: 'pending');

        $this->assertSame('0.0000', $this->balanceOf($this->card));
    }

    public function test_an_overpaid_card_reads_positive(): void
    {
        // The other face of the same rule: a card paid beyond its charges is a card
        // that owes the user, so the user is up. Positive, and still not clamped --
        // a figure that stopped at zero would report a credit as a card owing
        // nothing, which is the opposite of what happened.
        //
        // CardStatement::owed() calls the same state -50.0000, because a period's
        // debt and an account's position are different questions.
        $this->charge('2026-01-01', '100.0000');
        $this->row($this->card, 'payment', '150.0000', ['due_date' => '2026-02-09']);

        $this->assertSame('50.0000', $this->balanceOf($this->card));
    }

    public function test_a_card_balance_is_what_the_statements_owe_negated(): void
    {
        // The same money, counted from two sides, so the two figures must agree in
        // magnitude. They do not agree in sign, and that is not a disagreement: a
        // period's owed is a debt and reads positive, a card's balance is a position
        // and reads negative while it owes. Each is computed by its own query, which
        // is the only reason they can differ at all.
        //
        // A card table that disagreed with the panel beneath it in magnitude would be
        // worse than either sign. The settled period nets to zero; the outstanding
        // one does not.
        $this->charge('2026-01-01', '120.0000');
        $this->charge('2026-01-20', '80.5000');
        $this->row($this->card, 'payment', '120.0000', ['due_date' => '2026-02-09']);
        $this->charge('2026-02-26', '40.0000');

        $fromStatements = CardStatement::forAccount($this->card)
            ->reduce(
                fn (string $carry, CardStatement $statement) => BigDecimal::of($carry)
                    ->plus($statement->owed())
                    ->toScale(4)
                    ->toString(),
                '0.0000'
            );

        $this->assertSame('120.5000', $fromStatements);

        $this->assertSame(
            BigDecimal::of($fromStatements)->negated()->toString(),
            $this->balanceOf($this->card)
        );
    }

    // ---------------------------------------------------------------------
    // The money is decimal, and the query is one
    // ---------------------------------------------------------------------

    public function test_the_totals_keep_four_decimal_places(): void
    {
        $this->row($this->bank, 'income', '0.1000');
        $this->row($this->bank, 'income', '0.2000');

        $this->assertSame('0.3000', $this->balanceOf($this->bank));
    }

    public function test_a_figure_wider_than_a_float_could_hold_is_exact(): void
    {
        $this->row($this->bank, 'income', '0.1000');
        $this->row($this->bank, 'income', '0.2000');
        $this->row($this->bank, 'income', '12345678.9000');

        $this->assertSame('12345679.2000', $this->balanceOf($this->bank));
    }

    public function test_a_whole_page_costs_one_query(): void
    {
        // A balance is a sum over every row of its account, so it cannot be read off
        // the page of accounts. Reading it per account would make a five-row page five
        // scans, which is the thing Account::settlementAccount() already cannot afford
        // and this one can.
        $this->row($this->bank, 'income', '10.0000');
        $this->charge('2026-01-01', '25.0000');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $balances = AccountBalance::forAccounts(collect([$this->bank, $this->card, $this->brokerage]));

        $this->assertCount(2, $balances);
        $this->assertSame(1, $queries, 'Balances took more than one query for a page of three accounts.');
    }

    public function test_an_account_off_the_page_is_not_asked_about(): void
    {
        // The map is built from the accounts handed in, so a row that is not on this
        // page cannot appear in it and a stale figure cannot survive a page change.
        $other = $this->account('Other', 'cash');
        $this->row($other, 'income', '50.0000');

        $balances = AccountBalance::forAccounts(collect([$this->bank]));

        $this->assertArrayNotHasKey($other->id, $balances);
    }

    // ---------------------------------------------------------------------
    // The sign is stated once, and the enum is where it is stated
    // ---------------------------------------------------------------------

    public function test_the_sign_of_each_type_follows_the_account_it_sits_on(): void
    {
        $this->assertSame(1, TransactionType::Income->movesBalanceOn(AccountType::Cash));
        $this->assertSame(-1, TransactionType::Expense->movesBalanceOn(AccountType::Cash));
        $this->assertSame(-1, TransactionType::Transfer->movesBalanceOn(AccountType::Cash));

        $this->assertSame(-1, TransactionType::Charge->movesBalanceOn(AccountType::Card));
        $this->assertSame(1, TransactionType::Payment->movesBalanceOn(AccountType::Card));
    }

    public function test_a_type_says_zero_for_an_account_it_cannot_be_recorded_on(): void
    {
        // Unreachable through the DTO, which refuses the pairing, but zero rather than
        // a guess: a sign for a row that cannot exist is a claim nobody checked.
        $this->assertSame(0, TransactionType::Charge->movesBalanceOn(AccountType::Cash));
        $this->assertSame(0, TransactionType::Payment->movesBalanceOn(AccountType::Cash));
        $this->assertSame(0, TransactionType::Expense->movesBalanceOn(AccountType::Card));
    }

    public function test_a_trade_moves_no_balance_anywhere(): void
    {
        foreach (AccountType::cases() as $accountType) {
            $this->assertSame(0, TransactionType::Buy->movesBalanceOn($accountType));
            $this->assertSame(0, TransactionType::Sell->movesBalanceOn($accountType));
            $this->assertSame(0, TransactionType::Dividend->movesBalanceOn($accountType));
        }
    }

    // ---------------------------------------------------------------------
    // On the page
    // ---------------------------------------------------------------------

    public function test_the_account_page_sends_a_balance_for_every_account_that_has_one(): void
    {
        $this->row($this->bank, 'income', '500.0000');
        $this->row($this->bank, 'expense', '120.5000');
        $this->charge('2026-01-01', '80.0000');

        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            // Two of the three accounts: the brokerage has none, and is absent rather
            // than zero -- which is the same distinction the column makes.
            ->has('balances', 2)
            ->where('balances.'.$this->bank->id, '379.5000')
            ->where('balances.'.$this->card->id, '-80.0000')
            ->missing('balances.'.$this->brokerage->id)
        );
    }

    public function test_the_account_page_sends_a_balance_with_no_transactions(): void
    {
        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->where('balances.'.$this->bank->id, '0.0000')
            ->where('balances.'.$this->card->id, '0.0000')
        );
    }

    public function test_the_account_page_sends_nothing_when_there_are_no_accounts(): void
    {
        Account::query()->delete();

        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page->has('balances', 0));
    }

    // ---------------------------------------------------------------------

    private function balanceOf(Account $account): string
    {
        return AccountBalance::forAccounts(collect([$account]))[$account->id];
    }

    private function account(string $name, string $type, array $meta = []): Account
    {
        $account = Account::create(['name' => $name, 'status' => 'active', 'type' => $type, 'ccy' => 'HKD']);

        if ($meta !== []) {
            $account->meta()->create(['meta' => $meta]);
        }

        return $account;
    }

    private function charge(string $date, string $amount, array $extra = [], string $status = 'posted'): void
    {
        $this->chargeOn($this->card, $date, $amount, $extra + ['merchant' => 'Cafe'], $status);
    }

    private function chargeOn(
        Account $account,
        string $date,
        string $amount,
        array $extra = [],
        string $status = 'posted',
        ?array $bag = null
    ): void {
        $this->row(
            $account,
            'charge',
            $amount,
            $bag ?? ($extra + [
                'due_date' => CardStatementCycle::fromMeta($account->meta?->meta)
                    ?->dueDateFor(Carbon::parse($date))->toDateString(),
            ]),
            $status
        );
    }

    private function row(
        Account $account,
        string $type,
        string $amount,
        array $meta = [],
        string $status = 'posted'
    ): void {
        $transaction = Transaction::create([
            'account_id' => $account->id,
            'category_id' => null,
            'date' => '2026-01-01',
            'type' => $type,
            'description' => $type,
            'amount' => $amount,
            'ccy' => 'HKD',
            'status' => $status,
        ]);

        $meta = array_filter($meta, fn ($value) => $value !== null);

        if ($meta !== []) {
            $transaction->meta()->create(['meta' => $meta]);
        }
    }
}
