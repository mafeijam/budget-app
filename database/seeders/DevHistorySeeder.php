<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\CardStatement;
use App\Support\CardStatementCycle;
use Carbon\Carbon;
use Database\Seeders\Concerns\GuardsAgainstNonTestDatabase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Development fixtures: a year of ordinary life, so the cash flow chart has something
 * to draw.
 *
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevCategorySeeder
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevHistorySeeder
 *
 * On two accounts of its own, which it owns as DevTransactionSeeder owns its: every
 * transaction on them is deleted before writing. Not on Dev Card, whose settled
 * statement would reopen under back-dated charges, and not on accounts that seeder
 * wipes on every run.
 *
 * Dated relative to today and never after it, so the chart is always full. Every
 * statement already due is settled the way settle() does it, which is what keeps card
 * repayments out of spending on the chart; the newest is left owing.
 *
 * Seeded randomness, so a re-run writes the same rows.
 */
class DevHistorySeeder extends Seeder
{
    use GuardsAgainstNonTestDatabase;

    public const BANK = 'Dev History Cash';

    public const CARD = 'Dev History Card';

    private const MONTHS = 12;

    private const CATEGORIES = ['FOOD & DRINK', 'TRAVEL', 'CLOTHING & SHOE', 'HOME', 'MOBILE', 'BROADBAND', 'MOVIE', 'MUSIC', 'LEARNING', 'OTHER'];

    /** @var array<string, int> */
    private array $categories = [];

    public function run(): void
    {
        $this->guardAgainstNonTestDatabase(self::class);

        $this->categories = $this->categories();

        [$bank, $card] = $this->accounts();

        DB::transaction(function () use ($bank, $card) {
            $this->clear([$bank, $card]);

            mt_srand(2026);

            foreach ($this->months() as $n => $month) {
                $this->month($bank, $card, $month, $n);
            }

            $this->settle($bank, $card);
        });
    }

    /** Oldest first; the index counts back from the current month, which is 0. */
    private function months(): array
    {
        $months = [];

        for ($n = self::MONTHS - 1; $n >= 0; $n--) {
            $months[$n] = today()->startOfMonth()->subMonthsNoOverflow($n);
        }

        return $months;
    }

    private function month(Account $bank, Account $card, Carbon $month, int $n): void
    {
        // Paid on the 1st, before the rent, so the bank never dips below zero.
        $this->write($bank, $month, 1, 'deposit', 'Salary', $n <= 5 ? '44000' : '42000');
        $this->write($bank, $month, 2, 'withdraw', 'Rent', '15000', 'HOME');
        $this->write($bank, $month, 10, 'withdraw', 'Broadband', '288', 'BROADBAND');
        $this->write($bank, $month, 18, 'withdraw', 'Cash withdrawal', (string) (mt_rand(4, 10) * 100), 'OTHER');

        if ($month->month === 12) {
            $this->write($bank, $month, 20, 'deposit', 'Year-end bonus', '42000');
        }

        $this->write($card, $month, 12, 'charge', 'Mobile plan', '188', 'MOBILE');
        $this->write($card, $month, 15, 'charge', 'Music subscription', '68', 'MUSIC');

        foreach (range(1, mt_rand(6, 9)) as $_) {
            $this->write($card, $month, mt_rand(3, 27), 'charge', $this->pick(['Supermarket', 'Wet market', 'Bakery', 'Fruit stall']), $this->amount(60, 480), 'FOOD & DRINK');
        }

        foreach (range(1, mt_rand(3, 5)) as $_) {
            $this->write($card, $month, mt_rand(3, 27), 'charge', $this->pick(['Dinner', 'Dim sum', 'Ramen', 'Hotpot']), $this->amount(120, 650), 'FOOD & DRINK');
        }

        if (mt_rand(1, 10) <= 4) {
            $this->write($card, $month, mt_rand(3, 27), 'charge', $this->pick(['Uniqlo', 'Running shoes', 'Jacket']), $this->amount(300, 1800), 'CLOTHING & SHOE');
        }

        if (mt_rand(1, 2) === 1) {
            $this->write($card, $month, mt_rand(3, 27), 'charge', 'Cinema', '220', 'MOVIE');
        }

        if ($n % 3 === 1) {
            $this->write($card, $month, 8, 'charge', 'Online course', '1200', 'LEARNING');
        }

        // One trip, so a month of the chart spends more than it earns.
        if ($n === 4) {
            $this->write($card, $month, 5, 'charge', 'Flights to Tokyo', '9800', 'TRAVEL');
            $this->write($card, $month, 6, 'charge', 'Hotel, Tokyo', '12800', 'TRAVEL');
            $this->write($card, $month, 9, 'charge', 'Shopping, Tokyo', '7400', 'TRAVEL');
        }
    }

    /** Skipped for a day that has not come yet, so nothing is dated in the future. */
    private function write(Account $account, Carbon $month, int $day, string $type, string $description, string $amount, ?string $category = null): void
    {
        $date = $month->copy()->day(min($day, $month->daysInMonth));

        if ($date->isAfter(today())) {
            return;
        }

        $row = Transaction::create([
            'account_id' => $account->id,
            'category_id' => $category === null ? null : $this->categories[$category],
            'date' => $date->toDateString(),
            'type' => TransactionType::from($type)->value,
            'description' => $description,
            'amount' => $amount,
            'ccy' => $account->ccy,
            'status' => TransactionStatus::Posted->value,
        ]);

        if ($type === TransactionType::Charge->value) {
            $row->meta()->create(['meta' => [
                'due_date' => CardStatementCycle::fromMeta($account->meta->meta)->dueDateFor($date)->toDateString(),
            ]]);
        }
    }

    /** Every period already due, paid on its due date with the rows settle() writes. */
    private function settle(Account $bank, Account $card): void
    {
        foreach (CardStatement::forAccount($card) as $statement) {
            if ($statement->dueDate >= today()->toDateString() || $statement->isSettled()) {
                continue;
            }

            $payment = Transaction::create([
                'account_id' => $card->id,
                'category_id' => null,
                'date' => $statement->dueDate,
                'type' => TransactionType::Payment->value,
                'description' => "Statement {$statement->dueDate}",
                'amount' => $statement->owed(),
                'ccy' => $card->ccy,
                'status' => TransactionStatus::Posted->value,
            ]);

            $transfer = Transaction::create([
                'account_id' => $bank->id,
                'category_id' => null,
                'date' => $statement->dueDate,
                'type' => TransactionType::Withdraw->value,
                'description' => "Card payment [{$card->name}]",
                'amount' => $statement->owed(),
                'ccy' => $bank->ccy,
                'status' => TransactionStatus::Posted->value,
            ]);

            $payment->meta()->create(['meta' => ['due_date' => $statement->dueDate, 'paired_transaction_id' => $transfer->id]]);
            $transfer->meta()->create(['meta' => ['paired_transaction_id' => $payment->id]]);

            foreach (CardStatement::rowsInPeriod($card, $statement->dueDate) as $row) {
                if ($row->type === TransactionType::Charge->value) {
                    $row->meta->update(['meta' => [...$row->meta->meta->getArrayCopy(), 'settled_by' => $payment->id]]);
                }
            }
        }
    }

    /** @return array{0: Account, 1: Account} */
    private function accounts(): array
    {
        $attributes = ['status' => AccountStatus::Active->value, 'ccy' => Currency::Hkd->value];

        $bank = Account::updateOrCreate(['name' => self::BANK], $attributes + ['type' => AccountType::Cash->value]);
        $card = Account::updateOrCreate(['name' => self::CARD], $attributes + ['type' => AccountType::Card->value]);

        // Terms unlike the other dev cards', so a bug in one cycle is not hidden by another.
        $card->meta()->updateOrCreate(
            ['model_id' => $card->id, 'model_type' => Account::class],
            ['meta' => ['statement_day' => 20, 'term_days' => 20, 'settlement_account_id' => $bank->id]],
        );

        return [$bank, $card->fresh('meta')];
    }

    /**
     * Not a trade's cash side, which DevTradingSeeder owns: deleting it would leave the
     * trade linked to a row that no longer exists.
     *
     * @param  list<Account>  $accounts
     */
    private function clear(array $accounts): void
    {
        $owned = array_map(fn (Account $a) => $a->id, $accounts);

        $rows = Transaction::with('meta')->whereIn('account_id', $owned)->get();

        $partners = Transaction::whereIn('id', $rows->map(fn (Transaction $row) => $row->meta?->meta['paired_transaction_id'] ?? null)->filter())
            ->pluck('account_id', 'id');

        foreach ($rows as $row) {
            $partner = $row->meta?->meta['paired_transaction_id'] ?? null;

            if ($partner !== null && ! in_array($partners[$partner] ?? null, $owned, true)) {
                continue;
            }

            $row->meta()->delete();
            $row->delete();
        }
    }

    /** @return array<string, int> */
    private function categories(): array
    {
        $found = Category::whereIn('name', self::CATEGORIES)->pluck('id', 'name')->all();

        $missing = array_values(array_diff(self::CATEGORIES, array_keys($found)));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'DevHistorySeeder needs the dev categories, and %s %s not in the '
                ."database.\n\nRun the category fixtures first:\n"
                ."    DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevCategorySeeder\n",
                implode(', ', $missing),
                count($missing) === 1 ? 'is' : 'are',
            ));
        }

        return $found;
    }

    /** @param  list<string>  $choices */
    private function pick(array $choices): string
    {
        return $choices[mt_rand(0, count($choices) - 1)];
    }

    /** Whole cents, as a decimal string: a float never reaches the column. */
    private function amount(int $low, int $high): string
    {
        $cents = mt_rand($low * 100, $high * 100);

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
