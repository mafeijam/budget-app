<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;
use App\Services\YahooFinance;
use App\Support\Fx;
use App\Support\Positions;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Fetch closing prices for every symbol a brokerage still holds, and the FX rates that
 * convert the currencies held into the base one.
 *
 * The symbols come from the open positions, so there is no watch list to keep. A few days
 * back rather than today alone, so a missed run fills in the next time, and a close Yahoo
 * revised is taken as revised.
 *
 * --history reaches back instead: every symbol ever traded from its first trade, and each
 * FX pair from the first transaction in that currency, for the net worth history.
 */
class FetchPrices extends Command
{
    protected $signature = 'prices:fetch
        {--days=7 : How many days back to fetch}
        {--history : Every symbol ever traded, from its first trade, and FX from the first transaction}
        {--symbol=* : Only these symbols, rather than every one held}';

    protected $description = 'Fetch closing prices and FX rates from Yahoo';

    public function handle(YahooFinance $yahoo): int
    {
        $recent = today()->subDays((int) $this->option('days'))->toDateString();
        $history = (bool) $this->option('history');

        $targets = $this->stockTargets($history, $recent);

        if ($only = $this->option('symbol')) {
            $targets = array_intersect_key($targets, array_flip(array_map('strtoupper', $only)));
        } else {
            $targets += $this->fxTargets($history, $recent);
        }

        if ($targets === []) {
            $this->info('Nothing held, so no prices to fetch.');

            return self::SUCCESS;
        }

        $to = today()->toDateString();
        $failed = 0;

        foreach ($targets as $symbol => [$ccy, $from]) {
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
                $this->warn(str_ends_with($symbol, '=X')
                    ? "{$symbol} is quoted in {$quote['currency']}, not {$ccy}; skipped."
                    : "{$symbol} is quoted in {$quote['currency']} but held in a {$ccy} brokerage; skipped.");
                $failed++;

                continue;
            }

            $manual = Price::where('symbol', $symbol)->where('source', 'manual')->pluck('date')->flip();
            $written = 0;

            foreach ($quote['closes'] as $date => $close) {
                // A manual price exists because the fetched one was missing or wrong.
                if ($manual->has($date)) {
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
        return $failed === count($targets) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Each symbol with the currency of its brokerage and the day to fetch from.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function stockTargets(bool $history, string $recent): array
    {
        $targets = [];

        $brokers = Account::query()->where('type', AccountType::Security->value)->get();

        foreach ($brokers as $broker) {
            $trades = Positions::tradesOf($broker);

            foreach (Positions::fromTrades($trades) as $symbol => $position) {
                if (! $history && ! $position['open']) {
                    continue;
                }

                $first = collect($trades)->where('symbol', $symbol)->min('date');
                $from = $history ? $first : $recent;

                $targets[$symbol] = [$broker->ccy, min($targets[$symbol][1] ?? $from, $from)];
            }
        }

        ksort($targets);

        return $targets;
    }

    /**
     * An FX pair for each currency an account holds other than the base, quoted in the base.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function fxTargets(bool $history, string $recent): array
    {
        $targets = [];

        $currencies = Account::query()->distinct()->pluck('ccy')
            ->reject(fn (string $ccy) => $ccy === Fx::BASE->value);

        foreach ($currencies as $ccy) {
            $first = Transaction::query()
                ->whereHas('account', fn ($q) => $q->where('ccy', $ccy))
                ->min('date');

            if ($first === null) {
                continue;
            }

            $targets[Fx::pair($ccy)] = [Fx::BASE->value, $history ? $first : $recent];
        }

        return $targets;
    }
}
