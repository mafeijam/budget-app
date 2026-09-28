<?php

namespace Database\Seeders;

use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Database\Seeders\Concerns\GuardsAgainstNonTestDatabase;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Development fixtures: transactions on the cash and card dev accounts.
 *
 * Run it with an explicit class, after the other two, because it needs both:
 *
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevCategorySeeder
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevAccountSeeder
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevTransactionSeeder
 *
 * Cash and card only. A brokerage is left with no transactions, which is the same
 * reason it reports no balance: what a brokerage is worth needs a price this app
 * does not carry. Seeding trades there would put figures on screen that mean
 * nothing, and a fixture that looks like a feature is worse than no fixture.
 *
 * Unlike the other two seeders this one OWNS the rows: it deletes every
 * transaction on the accounts it names before writing, so re-running restores the
 * intended state instead of doubling it. The alternative -- a marker in the
 * description and a delete-then-recreate -- would leave a user's own row on a dev
 * account alone, at the cost of putting a marker in every description the fixtures
 * show in the transaction table. The accounts are named, so anything on them is
 * already a fixture by definition.
 *
 * The amounts are not filler. Each one is here to make a specific figure or
 * specific row type visible in the browser, and the seeder says which:
 *
 * - A charge in a currency the card is not, with the figure stated in the card's own
 *   currency. The account table's Balance column sums the stated figure, so a card
 *   that owes 780 and not 100 is the difference between a right column and a wrong
 *   one that still looks plausible.
 * - A pending charge and a pending deposit. Neither counts toward a balance, and both
 *   are on screen somewhere, so a build that counted them would show a number
 *   disagreeing with the rows above it.
 * - Two deposits of 0.1000 and 0.2000. A balance that has been through a float reads
 *   0.30000000000000004, and a column asserting its own arithmetic is a lie the eye
 *   cannot catch.
 * - A withdrawal and a payment for the same 900.0000, which is what a card settlement
 *   writes, so the two halves are recognisable as a pair.
 * - One card left owing something and one card fully settled, so the Balance column
 *   has both a figure and a zero to show rather than only one.
 */
class DevTransactionSeeder extends Seeder
{
    use GuardsAgainstNonTestDatabase;

    public function run(): void
    {
        $this->guardAgainstNonTestDatabase(self::class);

        $accounts = $this->accounts();

        $this->clear($accounts);

        $categories = $this->categories();

        foreach ($this->definitions() as $row) {
            $this->write($accounts[$row['account']], $row, $categories);
        }
    }

    // ---------------------------------------------------------------------
    // The rows
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, Account>  $accounts
     * @param  array<string, int|null>  $categories
     */
    private function write(Account $account, array $row, array $categories): void
    {
        $type = TransactionType::from($row['type']);

        $transaction = Transaction::create([
            'account_id' => $account->id,
            'category_id' => $row['category'] === null ? null : $categories[$row['category']],
            'date' => $row['date'],
            'type' => $type->value,
            'description' => $row['description'],
            'amount' => $row['amount'],
            'ccy' => $row['ccy'],
            'status' => $row['status'],
        ]);

        $meta = $row['meta'];

        // Derived BEFORE the emptiness check, not after. A charge's due date is the
        // thing that makes its bag non-empty, so filtering first and returning early
        // would skip the derivation and leave a charge that no statement query groups
        // by -- present in the card's transactions, absent from what the card owes,
        // with nothing reporting the gap. Nothing else in the bag is load-bearing for
        // that, so an early return here was only ever safe by accident.
        if ($type === TransactionType::Charge && ! array_key_exists('due_date', $meta)) {
            $cycle = CardStatementCycle::fromMeta($account->meta?->meta);

            $meta['due_date'] = $cycle?->dueDateFor(Carbon::parse($row['date']))->toDateString();
        }

        $meta = array_filter($meta, fn ($value) => $value !== null);

        if ($meta === []) {
            return;
        }

        $transaction->meta()->create(['meta' => $meta]);
    }

    /**
     * The dev accounts, by name, so the definitions below can be written as prose.
     *
     * @return array<string, Account>
     */
    private function accounts(): array
    {
        $names = array_column($this->definitions(), 'account');

        $found = Account::whereIn('name', $names)->get()->keyBy('name')->all();

        $missing = array_values(array_diff($names, array_keys($found)));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'DevTransactionSeeder needs the dev accounts, and %s %s not in the '
                ."database.\n\nRun the account fixtures first:\n"
                ."    DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevAccountSeeder\n",
                implode(', ', $missing),
                count($missing) === 1 ? 'is' : 'are',
            ));
        }

        return $found;
    }

    /**
     * @return array<string, int>
     */
    private function categories(): array
    {
        $names = array_values(array_unique(array_filter(
            array_column($this->definitions(), 'category'),
            fn (?string $name) => $name !== null
        )));

        $found = Category::whereIn('name', $names)->pluck('id', 'name')->all();

        $missing = array_values(array_diff($names, array_keys($found)));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'DevTransactionSeeder needs the dev categories, and %s %s not in the '
                ."database.\n\nRun the category fixtures first:\n"
                ."    DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevCategorySeeder\n",
                implode(', ', $missing),
                count($missing) === 1 ? 'is' : 'are',
            ));
        }

        return $found;
    }

    /**
     * Delete every transaction on the named accounts, bags included.
     *
     * @param  array<string, Account>  $accounts
     */
    private function clear(array $accounts): void
    {
        // array_map rather than array_column: a column read does not go through
        // Eloquent's __get, so $account->id is not a real property to be collected.
        $ids = array_map(fn (Account $account) => $account->id, $accounts);

        Transaction::whereIn('account_id', $ids)
            ->get()
            ->each(function (Transaction $transaction) {
                $transaction->meta()->delete();
                $transaction->delete();
            });
    }

    /**
     * @return list<array{
     *     account: string,
     *     date: string,
     *     type: string,
     *     description: string,
     *     amount: string,
     *     ccy: string,
     *     status: string,
     *     category: ?string,
     *     meta: array<string, mixed>
     * }>
     */
    private function definitions(): array
    {
        $hkd = Currency::Hkd->value;
        $posted = TransactionStatus::Posted->value;
        $pending = TransactionStatus::Pending->value;

        return [
            // ------------------------------------------------------------------
            // Dev Cash: money in, money out, and the withdrawal half of the card
            // settlement below. Leaves 2819.5000.
            // ------------------------------------------------------------------
            [
                'account' => 'Dev Cash',
                'date' => '2026-01-01',
                'type' => TransactionType::Deposit->value,
                'description' => 'Salary',
                'amount' => '5000.0000',
                'ccy' => $hkd,
                'status' => $posted,
                'category' => null,
                'meta' => [],
            ],
            [
                'account' => 'Dev Cash',
                'date' => '2026-01-05',
                'type' => TransactionType::Withdraw->value,
                'description' => 'Rent',
                'amount' => '1200.5000',
                'ccy' => $hkd,
                'status' => $posted,
                'category' => 'HOME',
                'meta' => [],
            ],
            [
                'account' => 'Dev Cash',
                'date' => '2026-01-12',
                'type' => TransactionType::Withdraw->value,
                'description' => 'Coffee',
                'amount' => '80.0000',
                'ccy' => $hkd,
                'status' => $posted,
                'category' => 'FOOD & DRINK',
                'meta' => [],
            ],

            // The far half of the settlement under Dev Card, for the same 900. A
            // withdrawal leaves a bank toward a far side this app does not track,
            // which is why the card half is a Payment and not a second withdrawal.
            [
                'account' => 'Dev Cash',
                'date' => '2026-02-01',
                'type' => TransactionType::Withdraw->value,
                'description' => 'Card payment [Dev Card]',
                'amount' => '900.0000',
                'ccy' => $hkd,
                'status' => $posted,
                'category' => null,
                'meta' => [],
            ],

            // ------------------------------------------------------------------
            // Dev Cash Reserve: the decimal case, and a pending row that must not
            // count. Leaves 0.3000 rather than 0.30000000000000004.
            // ------------------------------------------------------------------
            [
                'account' => 'Dev Cash Reserve',
                'date' => '2026-01-02',
                'type' => TransactionType::Deposit->value,
                'description' => 'Interest',
                'amount' => '0.1000',
                'ccy' => $hkd,
                'status' => $posted,
                'category' => null,
                'meta' => [],
            ],
            [
                'account' => 'Dev Cash Reserve',
                'date' => '2026-01-03',
                'type' => TransactionType::Deposit->value,
                'description' => 'Interest',
                'amount' => '0.2000',
                'ccy' => $hkd,
                'status' => $posted,
                'category' => null,
                'meta' => [],
            ],

            // Pending, and therefore not in the balance. The seeder would rather the
            // accounts table and the transaction table disagree in a way a reader can
            // check than agree by accident.
            [
                'account' => 'Dev Cash Reserve',
                'date' => '2026-01-04',
                'type' => TransactionType::Deposit->value,
                'description' => 'Refund pending',
                'amount' => '77.0000',
                'ccy' => $hkd,
                'status' => $pending,
                'category' => null,
                'meta' => [],
            ],

            // ------------------------------------------------------------------
            // Dev Card: two charges into one period, one of them cross-currency, and
            // the payment that settles it. 120 + 780 - 900 leaves 0.0000.
            // ------------------------------------------------------------------
            [
                'account' => 'Dev Card',
                'date' => '2026-01-10',
                'type' => TransactionType::Charge->value,
                'description' => 'Cafe',
                'amount' => '120.0000',
                'ccy' => $hkd,
                'status' => $posted,
                'category' => 'FOOD & DRINK',
                'meta' => [],
            ],

            // The one row whose amount is not what the card owes. The row is in USD
            // because that is what the merchant charged in, and card_amount is what
            // the user said it came to in HKD, which is the figure the balance sums.
            // Drop it and the card reads as owing 100 instead of 780.
            [
                'account' => 'Dev Card',
                'date' => '2026-01-11',
                'type' => TransactionType::Charge->value,
                'description' => 'US Store',
                'amount' => '100.0000',
                'ccy' => Currency::Usd->value,
                'status' => $posted,
                'category' => 'OTHER',
                'meta' => ['card_amount' => '780.0000'],
            ],

            // The due date is supplied rather than derived: a payment names the
            // statement it settles, which is a question about what is outstanding.
            // It is the period both charges above derive into.
            [
                'account' => 'Dev Card',
                'date' => '2026-02-01',
                'type' => TransactionType::Payment->value,
                'description' => 'Statement 2026-02-09',
                'amount' => '900.0000',
                'ccy' => $hkd,
                'status' => $posted,
                'category' => null,
                'meta' => ['due_date' => '2026-02-09'],
            ],

            // A second period, and the one the settle button hangs off. The 26th is
            // past this card's 25th closing, so this charge opens a period of its own
            // rather than joining the one above, and nothing pending sits in it --
            // which is the third of the three things settleable() asks for, the other
            // two being a bank to pay from and a period that is not already settled.
            //
            // 250.0000 left owing, so the dialog opens on a figure worth reading and
            // Dev Card's balance moves off 0.0000 to -250.0000.
            [
                'account' => 'Dev Card',
                'date' => '2026-02-26',
                'type' => TransactionType::Charge->value,
                'description' => 'Grocery',
                'amount' => '250.0000',
                'ccy' => $hkd,
                'status' => $posted,
                'category' => 'HOME',
                'meta' => [],
            ],

            // ------------------------------------------------------------------
            // Dev Card Everyday: one unpaid charge and one pending, so the panel has
            // a period it must refuse to settle as well as one it must allow. Leaves
            // -45.2500.
            // ------------------------------------------------------------------
            [
                'account' => 'Dev Card Everyday',
                'date' => '2026-01-20',
                'type' => TransactionType::Charge->value,
                'description' => 'Bakery',
                'amount' => '45.2500',
                'ccy' => $hkd,
                'status' => $posted,
                'category' => 'FOOD & DRINK',
                'meta' => [],
            ],
            [
                'account' => 'Dev Card Everyday',
                'date' => '2026-01-21',
                'type' => TransactionType::Charge->value,
                'description' => 'Pending Shop',
                'amount' => '99.0000',
                'ccy' => $hkd,
                'status' => $pending,
                'category' => 'OTHER',
                'meta' => [],
            ],
            [
                'account' => 'Dev Card Everyday',
                'date' => '2026-01-21',
                'type' => TransactionType::Charge->value,
                'description' => 'Pending Shop',
                'amount' => '99.0000',
                'ccy' => $hkd,
                'status' => $pending,
                'category' => 'OTHER',
                'meta' => [],
            ],

            // No brokerage rows, and that is deliberate rather than an oversight:
            // a trade's worth needs a price this app does not carry, and DevAccountSeeder
            // supplies two brokerages so this is reachable. A dividend would not need one
            // -- a deposit on a brokerage moves no balance and the positions page prints
            // it as received -- so the only reason there is none here is that inventing a
            // dividend is not this file's to do.
        ];
    }
}
