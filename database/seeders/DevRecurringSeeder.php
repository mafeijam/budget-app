<?php

namespace Database\Seeders;

use App\DTO\RecurringTransactionData;
use App\Enums\Frequency;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
use Carbon\Carbon;
use Database\Seeders\Concerns\GuardsAgainstNonTestDatabase;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Development fixtures: recurring transactions on the cash and card dev accounts.
 *
 * Run it with an explicit class, after the category and account seeders:
 *
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevRecurringSeeder
 *
 * It owns the rules on the accounts it names, as DevTransactionSeeder owns their
 * transactions: it deletes them before writing, so re-running restores this set.
 *
 * Every first date is the next matching day after today, so nothing is due when it runs
 * and it writes no transactions. A backdated rule would have the recorder fill those
 * accounts with pending rows that DevTransactionSeeder then deletes, while the rule's
 * last_recorded_on says they were written, so they would never come back.
 *
 * The set covers each state the page draws: a withdrawal and a deposit, card charges, a
 * salary on the 31st so the short-month clamp is on screen, a charge in another currency,
 * a yearly rule with an end date, and a paused one. No card payment: it writes only the
 * card's half, and an autopay fixture would teach that the bank moves too.
 */
class DevRecurringSeeder extends Seeder
{
    use GuardsAgainstNonTestDatabase;

    public function run(): void
    {
        $this->guardAgainstNonTestDatabase(self::class);

        $accounts = $this->accounts();
        $categories = $this->categories();

        RecurringTransaction::whereIn('account_id', array_map(fn (Account $a) => $a->id, $accounts))->delete();

        foreach ($this->definitions() as $row) {
            $start = self::nextOn($row['frequency'], $row['month'], $row['day']);

            // Through the DTO, so a fixture the form would refuse -- a charge in USD with
            // no card amount, a type the account does not take -- fails here instead.
            $data = RecurringTransactionData::from([
                'id' => null,
                'account_id' => $accounts[$row['account']]->id,
                'category_id' => $row['category'] === null ? null : $categories[$row['category']],
                'type' => $row['type'],
                'description' => $row['description'],
                'amount' => $row['amount'],
                'ccy' => $row['ccy'],
                'card_amount' => $row['card_amount'],
                'frequency' => $row['frequency'],
                'start_date' => $start->toDateString(),
                'end_date' => $row['years'] === null ? null : $start->copy()->addYearsNoOverflow($row['years'])->toDateString(),
                'active' => $row['active'],
            ]);

            RecurringTransaction::create($data->except('id')->toArray());
        }
    }

    /**
     * The first day after today that falls on $day (and $month, for a yearly rule).
     * Only an exact day anchors the rule: a start on the 30th would clamp nothing.
     */
    private static function nextOn(string $frequency, ?int $month, int $day): Carbon
    {
        $candidate = today()->addDay();

        while (true) {
            $matches = $candidate->day === $day
                && ($frequency === Frequency::Monthly->value || $candidate->month === $month);

            if ($matches) {
                return $candidate;
            }

            $candidate = $candidate->addDay();
        }
    }

    /** @return array<string, Account> */
    private function accounts(): array
    {
        $names = array_values(array_unique(array_column($this->definitions(), 'account')));

        $found = Account::whereIn('name', $names)->get()->keyBy('name')->all();

        $missing = array_values(array_diff($names, array_keys($found)));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'DevRecurringSeeder needs the dev accounts, and %s %s not in the '
                ."database.\n\nRun the account fixtures first:\n"
                ."    DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevAccountSeeder\n",
                implode(', ', $missing),
                count($missing) === 1 ? 'is' : 'are',
            ));
        }

        return $found;
    }

    /** @return array<string, int> */
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
                'DevRecurringSeeder needs the dev categories, and %s %s not in the '
                ."database.\n\nRun the category fixtures first:\n"
                ."    DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevCategorySeeder\n",
                implode(', ', $missing),
                count($missing) === 1 ? 'is' : 'are',
            ));
        }

        return $found;
    }

    /**
     * @return list<array{
     *     account: string,
     *     type: string,
     *     description: string,
     *     amount: string,
     *     ccy: string,
     *     card_amount: ?string,
     *     category: ?string,
     *     frequency: string,
     *     month: ?int,
     *     day: int,
     *     years: ?int,
     *     active: bool,
     * }>
     */
    private function definitions(): array
    {
        $row = fn (array $fields) => $fields + [
            'ccy' => 'HKD',
            'card_amount' => null,
            'category' => null,
            'frequency' => Frequency::Monthly->value,
            'month' => null,
            'years' => null,
            'active' => true,
        ];

        return [
            $row([
                'account' => 'Dev Cash',
                'type' => 'withdraw',
                'description' => 'Rent',
                'amount' => '15000.0000',
                'category' => 'HOME',
                'day' => 1,
            ]),
            $row([
                'account' => 'Dev Cash',
                'type' => 'deposit',
                'description' => 'Salary',
                'amount' => '42000.0000',
                'day' => 31,
            ]),
            $row([
                'account' => 'Dev Card',
                'type' => 'charge',
                'description' => 'Mobile plan',
                'amount' => '188.0000',
                'category' => 'MOBILE',
                'day' => 12,
            ]),
            $row([
                'account' => 'Dev Card',
                'type' => 'charge',
                'description' => 'Music subscription',
                'amount' => '10.9900',
                'ccy' => 'USD',
                'card_amount' => '85.8000',
                'category' => 'MUSIC',
                'day' => 20,
            ]),
            $row([
                'account' => 'Dev Card',
                'type' => 'charge',
                'description' => 'Broadband, annual',
                'amount' => '2388.0000',
                'category' => 'BROADBAND',
                'frequency' => Frequency::Yearly->value,
                'month' => 3,
                'day' => 15,
                'years' => 2,
            ]),
            $row([
                'account' => 'Dev Cash Reserve',
                'type' => 'deposit',
                'description' => 'Savings top-up',
                'amount' => '5000.0000',
                'day' => 5,
                'active' => false,
            ]),
        ];
    }
}
