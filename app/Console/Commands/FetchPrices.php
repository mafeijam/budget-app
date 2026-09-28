<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Price;
use App\Services\YahooFinance;
use App\Support\Positions;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Fetch recent closing prices for every symbol a brokerage still holds.
 *
 * The symbols come from the open positions, so there is no watch list to keep: a
 * symbol bought is fetched from then on, one sold out stops. A few days back rather
 * than today alone, so a missed run -- the machine was off, Yahoo refused -- fills in
 * the next time, and a close Yahoo revised is taken as revised.
 */
class FetchPrices extends Command
{
    protected $signature = 'prices:fetch
        {--days=7 : How many days back to fetch}
        {--symbol=* : Only these symbols, rather than every one held}';

    protected $description = 'Fetch recent closing prices from Yahoo for every symbol held';

    public function handle(YahooFinance $yahoo): int
    {
        $held = $this->heldSymbols();

        $symbols = $this->option('symbol')
            ? array_intersect_key($held, array_flip(array_map('strtoupper', $this->option('symbol'))))
            : $held;

        if ($symbols === []) {
            $this->info('Nothing held, so no prices to fetch.');

            return self::SUCCESS;
        }

        $to = today()->toDateString();
        $from = today()->subDays((int) $this->option('days'))->toDateString();
        $failed = 0;

        foreach ($symbols as $symbol => $ccy) {
            try {
                $quote = $yahoo->closes($symbol, $from, $to);
            } catch (RuntimeException $e) {
                $this->warn($e->getMessage());
                $failed++;

                continue;
            }

            // A price in another currency than the brokerage holding the symbol would
            // be valued as though it were in the brokerage's -- NVDA's USD read as HKD.
            if ($quote['currency'] !== $ccy) {
                $this->warn("{$symbol} is quoted in {$quote['currency']} but held in a {$ccy} brokerage; skipped.");
                $failed++;

                continue;
            }

            $written = 0;

            foreach ($quote['closes'] as $date => $close) {
                $existing = Price::where('symbol', $symbol)->where('date', $date)->first();

                // A manual price exists because the fetched one was missing or wrong.
                if ($existing?->source === 'manual') {
                    continue;
                }

                Price::updateOrCreate(
                    ['symbol' => $symbol, 'date' => $date],
                    ['close' => $close, 'ccy' => $ccy, 'source' => 'yahoo']
                );

                $written++;
            }

            $this->line("{$symbol}: {$written} day".($written === 1 ? '' : 's'));
        }

        // A failure is reported, not stored: the next run tries again.
        return $failed === count($symbols) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every symbol an open position holds, with the currency of its brokerage.
     *
     * @return array<string, string>
     */
    private function heldSymbols(): array
    {
        $held = [];

        $brokers = Account::query()->where('type', AccountType::Security->value)->get();

        foreach ($brokers as $broker) {
            foreach (Positions::forAccount($broker) as $symbol => $position) {
                if ($position['open']) {
                    $held[$symbol] = $broker->ccy;
                }
            }
        }

        ksort($held);

        return $held;
    }
}
