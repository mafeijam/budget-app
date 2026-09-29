<?php

namespace Database\Seeders;

use App\DTO\TransactionData;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;
use Database\Seeders\Concerns\GuardsAgainstNonTestDatabase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Development fixtures: a year of trading on a brokerage paid from the history bank, so
 * the Invested column and the Positions page have something to show.
 *
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevHistorySeeder
 *     DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevTradingSeeder
 *
 * Every row goes through TransactionData and write(), as a save from the form does, so
 * TradeCash writes and links each cash side and guardHoldings() refuses a sell the
 * fixtures got wrong. It owns the brokerage's rows and their cash sides, and deletes
 * both before writing; DevHistorySeeder leaves the cash sides alone when it re-runs.
 *
 * A monthly tracker-fund buy, a stock bought twice and half sold at a gain, another held
 * for its dividends. Prices are seeded only for a symbol with none, so a close fetched
 * from Yahoo is never overwritten by an invented one.
 */
class DevTradingSeeder extends Seeder
{
    use GuardsAgainstNonTestDatabase;

    public const BROKER = 'Dev History Brokerage';

    private const SOURCE = 'seed';

    /** Closes that look right for each symbol, for a Positions page with no fetch yet. */
    private const PRICES = ['2800.HK' => '26.8000', '0700.HK' => '545.0000', '0005.HK' => '96.5000'];

    public function run(): void
    {
        $this->guardAgainstNonTestDatabase(self::class);

        $bank = Account::where('name', DevHistorySeeder::BANK)->first();

        if ($bank === null) {
            throw new RuntimeException(
                'DevTradingSeeder needs the history bank ['.DevHistorySeeder::BANK.'], which is not in '
                ."the database.\n\nRun the history fixtures first:\n"
                ."    DB_DATABASE=budget_v2_testing php artisan db:seed --class=DevHistorySeeder\n"
            );
        }

        $broker = Account::updateOrCreate(['name' => self::BROKER], [
            'status' => AccountStatus::Active->value,
            'type' => AccountType::Security->value,
            'ccy' => Currency::Hkd->value,
        ]);

        $broker->meta()->updateOrCreate(
            ['model_id' => $broker->id, 'model_type' => Account::class],
            ['meta' => ['settlement_account_id' => $bank->id]],
        );

        DB::transaction(function () use ($broker) {
            $this->clear($broker);

            foreach ($this->rows() as $row) {
                $this->write($broker, ...$row);
            }

            $this->prices();
        });
    }

    /**
     * Months back from the current one, which is 0, oldest first so a sell follows the
     * buys it sells from.
     *
     * @return list<array{0: int, 1: int, 2: string, 3: string, 4: array<string, string>, 5?: string}>
     */
    private function rows(): array
    {
        $rows = [];

        // The tracker fund on the 5th of every month, at a price drifting up the year.
        foreach (range(11, 0) as $n) {
            $price = number_format(22.4 + (11 - $n) * 0.38, 2, '.', '');
            $rows[] = [$n, 5, 'buy', 'Monthly 2800.HK', ['symbol' => '2800.HK', 'quantity' => '200', 'unit_price' => $price, 'fees' => '15']];
        }

        $rows[] = [10, 14, 'buy', 'Tencent', ['symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '382.40', 'fees' => '60']];
        $rows[] = [9, 20, 'buy', 'HSBC', ['symbol' => '0005.HK', 'quantity' => '400', 'unit_price' => '68.15', 'fees' => '45']];
        $rows[] = [7, 11, 'dividend', 'Dividend 0005.HK', ['symbol' => '0005.HK'], '320.00'];
        $rows[] = [6, 8, 'buy', 'Tencent', ['symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '418.80', 'fees' => '65']];
        $rows[] = [4, 22, 'dividend', 'Dividend 2800.HK', ['symbol' => '2800.HK'], '410.00'];
        $rows[] = [3, 11, 'dividend', 'Dividend 0005.HK', ['symbol' => '0005.HK'], '360.00'];
        $rows[] = [2, 16, 'sell', 'Tencent, half', ['symbol' => '0700.HK', 'quantity' => '100', 'unit_price' => '521.60', 'fees' => '70']];

        usort($rows, fn (array $a, array $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return $rows;
    }

    /** Skipped for a day that has not come yet, so nothing is dated in the future. */
    private function write(Account $broker, int $monthsBack, int $day, string $type, string $description, array $meta, ?string $amount = null): void
    {
        $month = today()->startOfMonth()->subMonthsNoOverflow($monthsBack);
        $date = $month->copy()->day(min($day, $month->daysInMonth));

        if ($date->isAfter(today())) {
            return;
        }

        $data = TransactionData::from([
            'id' => null,
            'account_id' => $broker->id,
            'category_id' => null,
            'date' => $date->toDateString(),
            'type' => $type,
            'description' => $description,
            'amount' => $amount,
            'ccy' => $broker->ccy,
            'status' => 'posted',
            'meta_data' => $meta,
            'created_at' => null,
        ]);

        $data->guardHoldings();
        $data->write();
    }

    /** The brokerage's rows and the cash sides TradeCash wrote for them. */
    private function clear(Account $broker): void
    {
        $rows = Transaction::with('meta')->where('account_id', $broker->id)->get();

        $cash = $rows->map(fn (Transaction $row) => $row->meta?->meta['paired_transaction_id'] ?? null)->filter();

        foreach (Transaction::whereIn('id', $cash)->get()->concat($rows) as $row) {
            $row->meta()->delete();
            $row->delete();
        }

        Price::whereIn('symbol', array_keys(self::PRICES))->where('source', self::SOURCE)->delete();
    }

    private function prices(): void
    {
        foreach (self::PRICES as $symbol => $close) {
            if (Price::where('symbol', $symbol)->exists()) {
                continue;
            }

            Price::create([
                'symbol' => $symbol,
                'date' => today()->toDateString(),
                'close' => $close,
                'ccy' => Currency::Hkd->value,
                'source' => self::SOURCE,
            ]);
        }
    }
}
