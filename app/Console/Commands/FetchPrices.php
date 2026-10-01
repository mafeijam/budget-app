<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Price;
use App\Models\Symbol;
use App\Models\Transaction;
use App\Services\YahooFinance;
use App\Support\Fx;
use App\Support\Positions;
use Carbon\Carbon;
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
 *
 * --at looks back from a past day instead of today: what was held then, over the days
 * before it, for the Positions page's look-back.
 */
class FetchPrices extends Command
{
    protected $signature = 'prices:fetch
        {--days=7 : How many days back to fetch}
        {--history : Every symbol ever traded, from its first trade, and FX from the first transaction}
        {--symbol=* : Only these symbols or FX pairs, rather than every one held}
        {--at= : Fetch up to this past day (Y-m-d), for what was held then}';

    protected $description = 'Fetch closing prices and FX rates from Yahoo';

    public function handle(YahooFinance $yahoo): int
    {
        $at = $this->option('at');

        if ($at !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) || $at > today()->toDateString())) {
            $this->error("--at must be a day no later than today, not [{$at}].");

            return self::INVALID;
        }

        $to = $at ?? today()->toDateString();
        $recent = Carbon::parse($to)->subDays((int) $this->option('days'))->toDateString();
        $history = (bool) $this->option('history');

        $targets = $this->stockTargets($history, $recent, $to) + $this->fxTargets($history, $recent);

        // From both lists, so an FX pair can be named too: a currency's first account needs its
        // rate's history, and fetching it alone beats every symbol ever traded from its first.
        if ($only = $this->option('symbol')) {
            $targets = array_intersect_key($targets, array_flip(array_map('strtoupper', $only)));
        }

        if ($targets === []) {
            $this->info('Nothing held, so no prices to fetch.');

            return self::SUCCESS;
        }

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

            // A rate has no name worth showing, and a name set by hand is kept.
            if ($quote['name'] !== null && ! str_ends_with($symbol, '=X')
                && ! Symbol::where('symbol', $symbol)->where('source', 'manual')->exists()) {
                Symbol::updateOrCreate(['symbol' => $symbol], ['name' => $quote['name'], 'source' => 'yahoo']);
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
    private function stockTargets(bool $history, string $recent, string $to): array
    {
        $targets = [];

        $brokers = Account::query()->where('type', AccountType::Security->value)->get();

        foreach ($brokers as $broker) {
            $trades = array_values(array_filter(Positions::tradesOf($broker), fn (array $trade) => $trade['date'] <= $to));

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

            // A week before the first row: a rate is the last close on or before a day, and the
            // first row is often dated on a holiday with none -- an opening balance on 1 January
            // had no rate at all, and its currency was left out of every total that day.
            $targets[Fx::pair($ccy)] = [Fx::BASE->value, $history ? Carbon::parse($first)->subDays(7)->toDateString() : $recent];
        }

        return $targets;
    }
}
