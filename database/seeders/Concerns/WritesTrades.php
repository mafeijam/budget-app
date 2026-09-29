<?php

namespace Database\Seeders\Concerns;

use App\DTO\TransactionData;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;

/**
 * Writing a brokerage's history the way the form does: through TransactionData and
 * write(), so TradeCash writes and links each cash side and guardHoldings() refuses a
 * sell the fixtures got wrong. A dividend is written on the brokerage's bank, naming it.
 */
trait WritesTrades
{
    /**
     * Months back from the current one, which is 0. Skipped for a day that has not come
     * yet, so nothing is dated in the future.
     *
     * @param  array<string, string>  $meta
     */
    protected function trade(Account $broker, int $monthsBack, int $day, string $type, string $description, array $meta, ?string $amount = null): void
    {
        $month = today()->startOfMonth()->subMonthsNoOverflow($monthsBack);
        $date = $month->copy()->day(min($day, $month->daysInMonth));

        if ($date->isAfter(today())) {
            return;
        }

        $dividend = $type === TransactionType::Dividend->value;
        $account = $dividend ? $broker->settlementAccount() : $broker;

        if ($dividend) {
            $meta['brokerage_account_id'] = $broker->id;
        }

        $data = TransactionData::from([
            'id' => null,
            'account_id' => $account->id,
            'category_id' => null,
            'date' => $date->toDateString(),
            'type' => $type,
            'description' => $description,
            'amount' => $amount,
            'ccy' => $account->ccy,
            'status' => 'posted',
            'meta_data' => $meta,
            'created_at' => null,
        ]);

        $data->guardHoldings();
        $data->write();
    }

    /**
     * Oldest first, so a sell follows the buys it sells from.
     *
     * @param  list<array{0: int, 1: int}>  $rows  each starting with months back and day
     * @return list<array>
     */
    protected function inDateOrder(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return $rows;
    }

    /** A brokerage's rows, the cash sides TradeCash wrote for them, and its dividends. */
    protected function clearTrades(Account $broker): void
    {
        $dividends = Transaction::with('meta')
            ->where('type', TransactionType::Dividend->value)
            ->get()
            ->filter(fn (Transaction $row) => (int) ($row->meta?->meta['brokerage_account_id'] ?? 0) === $broker->id);

        $rows = Transaction::with('meta')->where('account_id', $broker->id)->get()->concat($dividends);

        $cash = $rows->map(fn (Transaction $row) => $row->meta?->meta['paired_transaction_id'] ?? null)->filter();

        foreach (Transaction::whereIn('id', $cash)->get()->concat($rows) as $row) {
            $row->meta()->delete();
            $row->delete();
        }
    }

    /**
     * Invented closes, only for a symbol with no price: one fetched from Yahoo or typed
     * on the Positions page is the better figure, and a re-run must not overwrite it.
     *
     * @param  array<string, string>  $closes
     */
    protected function seedPrices(array $closes, string $ccy): void
    {
        Price::whereIn('symbol', array_keys($closes))->where('source', 'seed')->delete();

        foreach ($closes as $symbol => $close) {
            if (Price::where('symbol', $symbol)->exists()) {
                continue;
            }

            Price::create([
                'symbol' => $symbol,
                'date' => today()->toDateString(),
                'close' => $close,
                'ccy' => $ccy,
                'source' => 'seed',
            ]);
        }
    }
}
