<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Database\Seeders\Concerns\GuardsAgainstNonTestDatabase;
use Database\Seeders\Concerns\WritesTrades;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Development fixtures: a year of US trading, so Positions has a second currency and the
 * All view has to total HKD and USD apart.
 *
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevUsdTradingSeeder
 *
 * A USD brokerage settles only into a USD bank, so it brings its own, and owns every row
 * on both. The bank is funded by an opening deposit dated thirteen months back, outside
 * the cash flow chart's window: inside it, that deposit would be a month of income that
 * never happened. Within the window the bank's only income is dividends.
 *
 * An index fund bought every month, a stock bought twice and trimmed at a gain, and one
 * bought and sold out at a loss, so the sold-out toggle and a negative realised figure
 * both have something to show.
 */
class DevUsdTradingSeeder extends Seeder
{
    use GuardsAgainstNonTestDatabase;
    use WritesTrades;

    public const BANK = 'Dev USD Cash';

    public const BROKER = 'Dev USD Brokerage';

    private const OPENING = '50000.00';

    private const PRICES = ['VOO' => '571.2000', 'NVDA' => '178.4000', 'AAPL' => '231.1000'];

    public function run(): void
    {
        $this->guardAgainstNonTestDatabase(self::class);

        $attributes = ['status' => AccountStatus::Active->value, 'ccy' => Currency::Usd->value];

        $bank = Account::updateOrCreate(['name' => self::BANK], $attributes + ['type' => AccountType::Cash->value]);
        $broker = Account::updateOrCreate(['name' => self::BROKER], $attributes + ['type' => AccountType::Security->value]);

        $broker->meta()->updateOrCreate(
            ['model_id' => $broker->id, 'model_type' => Account::class],
            ['meta' => ['settlement_account_id' => $bank->id]],
        );

        DB::transaction(function () use ($bank, $broker) {
            $this->clearTrades($broker);

            Transaction::where('account_id', $bank->id)->get()->each(function (Transaction $row) {
                $row->meta()->delete();
                $row->delete();
            });

            Transaction::create([
                'account_id' => $bank->id,
                'category_id' => null,
                'date' => today()->startOfMonth()->subMonthsNoOverflow(12)->toDateString(),
                'type' => TransactionType::Deposit->value,
                'description' => 'Opening balance',
                'amount' => self::OPENING,
                'ccy' => $bank->ccy,
                'status' => TransactionStatus::Posted->value,
            ]);

            foreach ($this->rows() as $row) {
                $this->trade($broker, ...$row);
            }

            $this->seedPrices(self::PRICES, Currency::Usd->value);
        });
    }

    /** @return list<array{0: int, 1: int, 2: string, 3: string, 4: array<string, string>, 5?: string}> */
    private function rows(): array
    {
        $rows = [];

        // The index fund on the 10th of every month, at a price drifting up the year.
        foreach (range(11, 0) as $n) {
            $price = number_format(486.2 + (11 - $n) * 7.4, 2, '.', '');
            $rows[] = [$n, 10, 'buy', 'Monthly VOO', ['symbol' => 'VOO', 'quantity' => '5', 'unit_price' => $price, 'fees' => '1']];
        }

        $rows[] = [11, 18, 'buy', 'NVIDIA', ['symbol' => 'NVDA', 'quantity' => '40', 'unit_price' => '118.25', 'fees' => '1']];
        $rows[] = [9, 6, 'buy', 'Apple', ['symbol' => 'AAPL', 'quantity' => '30', 'unit_price' => '228.40', 'fees' => '1']];
        $rows[] = [5, 21, 'buy', 'NVIDIA', ['symbol' => 'NVDA', 'quantity' => '20', 'unit_price' => '135.10', 'fees' => '1']];
        $rows[] = [3, 14, 'sell', 'Apple, all', ['symbol' => 'AAPL', 'quantity' => '30', 'unit_price' => '213.75', 'fees' => '1']];
        $rows[] = [1, 12, 'sell', 'NVIDIA, trim', ['symbol' => 'NVDA', 'quantity' => '30', 'unit_price' => '172.60', 'fees' => '1']];

        foreach ([9 => '12.10', 6 => '20.85', 3 => '29.40', 0 => '37.95'] as $n => $amount) {
            $rows[] = [$n, 27, 'dividend', 'Dividend VOO', ['symbol' => 'VOO'], $amount];
        }

        return $this->inDateOrder($rows);
    }
}
