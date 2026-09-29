<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\Price;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Seeders\Concerns\GuardsAgainstNonTestDatabase;
use Database\Seeders\Concerns\WritesTrades;
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
 * It owns the brokerage's rows and their cash sides, and deletes both before writing;
 * DevHistorySeeder leaves the cash sides alone when it re-runs.
 *
 * A monthly tracker-fund buy, a stock bought twice and half sold at a gain, another held
 * for its dividends, and one bought near a top and held at a loss.
 */
class DevTradingSeeder extends Seeder
{
    use GuardsAgainstNonTestDatabase;
    use WritesTrades;

    public const BROKER = 'Dev History Brokerage';

    /** Closes that look right for each symbol, for a Positions page with no fetch yet. */
    private const PRICES = ['2800.HK' => '26.8000', '0700.HK' => '545.0000', '0005.HK' => '96.5000', '9988.HK' => '118.0000'];

    /** Bought at this multiple of its latest close, so it shows a loss whatever Yahoo says. */
    private const LOSS_MARKUP = '1.45';

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
            $this->clearTrades($broker);

            foreach ($this->rows() as $row) {
                $this->trade($broker, ...$row);
            }

            $this->seedPrices(self::PRICES, Currency::Hkd->value);
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
        $rows[] = [8, 12, 'buy', 'Alibaba', ['symbol' => '9988.HK', 'quantity' => '500', 'unit_price' => $this->lossPrice('9988.HK'), 'fees' => '55']];

        return $this->inDateOrder($rows);
    }

    /** A buy price above the symbol's latest close, fetched or seeded. */
    private function lossPrice(string $symbol): string
    {
        $close = Price::where('symbol', $symbol)->orderByDesc('date')->value('close') ?? self::PRICES[$symbol];

        return (string) BigDecimal::of($close)->multipliedBy(self::LOSS_MARKUP)->toScale(2, RoundingMode::HalfUp);
    }
}
