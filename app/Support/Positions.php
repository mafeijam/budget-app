<?php

namespace App\Support;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a brokerage holds, worked out from its trades rather than stored.
 *
 * A position typed in beside the trades would be a second record of the same facts,
 * and the two would disagree the first time a trade was corrected -- the reason card
 * statements are derived too. So there is nothing to keep in step: the trades are the
 * record and this reads them.
 *
 * Replayed in date order, then id, because a position is a running figure: a sell
 * is checked against what was held on its own day, and a sell dated before the buy it
 * sells from is an error even though the totals would balance.
 *
 * Average cost. A buy adds its quantity and what it cost including fees; a sell
 * removes its quantity and that share of the cost, and the difference between its
 * net proceeds and the cost it removes is realised. Every figure is a BigDecimal --
 * quantity has eight places and money four, and a float has no decimal places at all.
 *
 * Every status counts, pending included. A pending trade is one that has not settled
 * in cash, but the shares were bought or sold on the trade date; holding them is not
 * the balance question TransactionStatus::countsTowardBalance() answers.
 */
class Positions
{
    /** Places carried through the average-cost division before anything is rounded. */
    private const WORKING_SCALE = 12;

    /**
     * Every symbol a brokerage has traded, keyed by symbol, including ones since sold
     * out -- a closed position still carries what it realised.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forAccount(Account $broker): array
    {
        return self::replay(self::tradesOf($broker))['positions'];
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
     * A brokerage's buys and sells as plain rows, in the order they are replayed.
     *
     * Plain rows rather than models, so a caller asking "what if" can add, drop or
     * replace one before replaying -- which is how a sell is checked before it is saved.
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

    /**
     * One trade as a row, or null for one with no symbol or quantity to count.
     *
     * The symbol trimmed and upper-cased, so "nvda" and "NVDA " are one position.
     */
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
                'trades' => 0,
                'last_trade_date' => null,
            ];

            $position = &$book[$symbol];

            $quantity = BigDecimal::of($trade['quantity']);
            $gross = $quantity->multipliedBy(BigDecimal::of($trade['unit_price']));
            $fees = BigDecimal::of($trade['fees']);

            if ($trade['type'] === TransactionType::Buy->value) {
                $position['quantity'] = $position['quantity']->plus($quantity);
                $position['cost'] = $position['cost']->plus($gross)->plus($fees);
            } else {
                if ($quantity->isGreaterThan($position['quantity'])) {
                    $shortfall ??= [
                        'symbol' => $symbol,
                        'date' => $trade['date'],
                        'held' => self::quantity($position['quantity']),
                        'selling' => self::quantity($quantity),
                    ];

                    // Nothing held to take a cost from: the replay goes on, so the
                    // positions still read, but the refusal is what the caller acts on.
                    $removed = $position['cost'];
                } else {
                    $removed = $position['cost']
                        ->multipliedBy($quantity)
                        ->dividedBy($position['quantity'], self::WORKING_SCALE, RoundingMode::HalfUp);
                }

                $position['quantity'] = $position['quantity']->minus($quantity);
                $position['cost'] = $position['cost']->minus($removed);
                $position['realised'] = $position['realised']->plus($gross->minus($fees)->minus($removed));

                // Sold out: whatever cost is left is rounding in the average, not money.
                if (! $position['quantity']->isPositive()) {
                    $position['cost'] = $zero;
                }
            }

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
