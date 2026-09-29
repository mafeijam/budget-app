<?php

namespace App\Support;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A brokerage's holdings, replayed from its trades rather than stored.
 *
 * In date order, then id: a sell dated before the buy it sells from is a shortfall even
 * though the totals balance.
 *
 * Average cost on price alone, with fees as their own total; the cash side still carries
 * them. Pending trades count: the shares moved on the trade date, whatever the cash did.
 */
class Positions
{
    /** Places carried through the average-cost division before anything is rounded. */
    private const WORKING_SCALE = 12;

    /**
     * Sold-out symbols included: a closed position still carries what it realised.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forAccount(Account $broker): array
    {
        return self::replay(self::tradesOf($broker))['positions'];
    }

    /**
     * One valuation for the Positions page and the accounts list. A price in another
     * currency is ignored rather than summed as though it were the same money, and
     * `unpriced` counts the open positions missing from the totals.
     *
     * @return array{positions: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public static function valued(Account $broker): array
    {
        $positions = array_values(self::forAccount($broker));
        $prices = Price::latestFor(array_column($positions, 'symbol'), today()->toDateString());

        $positions = array_map(function (array $position) use ($prices, $broker) {
            $price = $prices[$position['symbol']] ?? null;
            $price = $price?->ccy === $broker->ccy ? $price : null;

            if (! $position['open'] || $price === null) {
                return $position + ['price' => null, 'price_date' => null, 'price_source' => null,
                    'market_value' => null, 'unrealised' => null];
            }

            $value = BigDecimal::of($position['quantity'])->multipliedBy($price->close)->toScale(4, RoundingMode::HalfUp);

            return $position + [
                'price' => (string) BigDecimal::of($price->close)->toScale(4),
                'price_date' => $price->date,
                'price_source' => $price->source,
                'market_value' => (string) $value,
                'unrealised' => (string) $value->minus($position['cost'])->toScale(4),
            ];
        }, $positions);

        $open = array_values(array_filter($positions, fn (array $p) => $p['open']));
        $priced = array_values(array_filter($open, fn (array $p) => $p['market_value'] !== null));

        $sum = fn (array $values) => self::money(array_reduce(
            $values,
            fn (BigDecimal $total, string $value) => $total->plus($value),
            BigDecimal::zero()
        ));

        return [
            'positions' => $positions,
            'totals' => [
                'open_cost' => $sum(array_column($open, 'cost')),
                'realised' => $sum(array_column($positions, 'realised')),
                'fees' => $sum(array_column($positions, 'fees')),
                'market_value' => $sum(array_column($priced, 'market_value')),
                'unrealised' => $sum(array_column($priced, 'unrealised')),
                'unpriced' => count($open) - count($priced),
                'open' => count($open),
            ],
        ];
    }

    /**
     * Open positions, for the dividend picker. A suggestion, not a rule: a position sold
     * after the ex-date still pays out.
     *
     * @return list<string>
     */
    public static function heldSymbols(Account $broker): array
    {
        $positions = self::replay(self::tradesOf($broker))['positions'];

        return array_values(array_keys(array_filter(
            $positions,
            fn (array $position) => $position['open']
        )));
    }

    /**
     * The first sell that sells more than was held on its day, or null when none does.
     *
     * @param  list<array<string, mixed>>  $trades  as tradesOf() returns them
     * @return array{symbol: string, date: string, held: string, selling: string}|null
     */
    public static function shortfall(array $trades): ?array
    {
        return self::replay($trades)['shortfall'];
    }

    /**
     * Plain rows, so a sell can be checked by adding it before replaying.
     *
     * @return list<array{id: int, date: string, type: string, symbol: string, quantity: string, unit_price: string, fees: string}>
     */
    public static function tradesOf(Account $broker): array
    {
        return Transaction::query()
            ->with('meta')
            ->where('account_id', $broker->id)
            ->whereIn('type', [TransactionType::Buy->value, TransactionType::Sell->value])
            ->get()
            ->map(fn (Transaction $row) => self::trade(
                $row->id,
                $row->date,
                $row->type,
                $row->meta?->meta?->getArrayCopy() ?? []
            ))
            ->filter()
            ->values()
            ->all();
    }

    /** Null without a symbol or quantity. Normalised so "nvda" and "NVDA " are one position. */
    public static function trade(int $id, string $date, string $type, array $meta): ?array
    {
        $symbol = strtoupper(trim((string) ($meta['symbol'] ?? '')));

        if ($symbol === '' || ($meta['quantity'] ?? null) === null) {
            return null;
        }

        return [
            'id' => $id,
            'date' => $date,
            'type' => $type,
            'symbol' => $symbol,
            'quantity' => (string) $meta['quantity'],
            'unit_price' => (string) ($meta['unit_price'] ?? '0'),
            'fees' => (string) ($meta['fees'] ?? '0'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $trades
     * @return array{positions: array<string, array<string, mixed>>, shortfall: ?array}
     */
    private static function replay(array $trades): array
    {
        usort($trades, fn (array $a, array $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        $zero = BigDecimal::zero();
        $book = [];
        $shortfall = null;

        foreach ($trades as $trade) {
            $symbol = $trade['symbol'];

            $book[$symbol] ??= [
                'quantity' => $zero,
                'cost' => $zero,
                'realised' => $zero,
                'fees' => $zero,
                'trades' => 0,
                'last_trade_date' => null,
            ];

            $position = &$book[$symbol];

            $quantity = BigDecimal::of($trade['quantity']);
            $gross = $quantity->multipliedBy(BigDecimal::of($trade['unit_price']));
            $fees = BigDecimal::of($trade['fees']);

            if ($trade['type'] === TransactionType::Buy->value) {
                $position['quantity'] = $position['quantity']->plus($quantity);
                $position['cost'] = $position['cost']->plus($gross);
            } else {
                if ($quantity->isGreaterThan($position['quantity'])) {
                    $shortfall ??= [
                        'symbol' => $symbol,
                        'date' => $trade['date'],
                        'held' => self::quantity($position['quantity']),
                        'selling' => self::quantity($quantity),
                    ];

                    // Replayed on, so the positions still read; the caller acts on the shortfall.
                    $removed = $position['cost'];
                } else {
                    $removed = $position['cost']
                        ->multipliedBy($quantity)
                        ->dividedBy($position['quantity'], self::WORKING_SCALE, RoundingMode::HalfUp);
                }

                $position['quantity'] = $position['quantity']->minus($quantity);
                $position['cost'] = $position['cost']->minus($removed);
                $position['realised'] = $position['realised']->plus($gross->minus($removed));

                // Sold out: whatever cost is left is rounding in the average, not money.
                if (! $position['quantity']->isPositive()) {
                    $position['cost'] = $zero;
                }
            }

            $position['fees'] = $position['fees']->plus($fees);
            $position['trades']++;
            $position['last_trade_date'] = $trade['date'];

            unset($position);
        }

        ksort($book);

        $positions = [];

        foreach ($book as $symbol => $position) {
            $held = $position['quantity']->isPositive();

            $positions[$symbol] = [
                'symbol' => $symbol,
                'quantity' => self::quantity($held ? $position['quantity'] : $zero),
                'cost' => self::money($position['cost']),
                'average_cost' => $held
                    ? self::money($position['cost']->dividedBy($position['quantity'], self::WORKING_SCALE, RoundingMode::HalfUp))
                    : null,
                'realised' => self::money($position['realised']),
                'fees' => self::money($position['fees']),
                'trades' => $position['trades'],
                'last_trade_date' => $position['last_trade_date'],
                'open' => $held,
            ];
        }

        return ['positions' => $positions, 'shortfall' => $shortfall];
    }

    /** A quantity for a sentence, without the zeros its eight places carry: 50, not 50.00000000. */
    public static function plain(string $quantity): string
    {
        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        $trimmed = rtrim(rtrim($quantity, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }

    /** Eight places, the scale quantity is validated to. */
    private static function quantity(BigDecimal $value): string
    {
        return (string) $value->toScale(8, RoundingMode::HalfUp);
    }

    /** Four places, the scale the amount column holds. */
    private static function money(BigDecimal $value): string
    {
        return (string) $value->toScale(4, RoundingMode::HalfUp);
    }
}
