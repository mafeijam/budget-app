<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Price;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What everything held is worth on a day, in the base currency: cash, less what the cards
 * owe, plus the stocks at their last close on or before it.
 *
 * Read from the rows rather than stored, so a corrected transaction corrects every past
 * snapshot too. A holding with no close yet counts at cost, and one in a currency with no
 * rate yet is left out; both are counted, so a total missing something says so.
 */
class NetWorth
{
    /** The point spacings offered, in months. */
    public const PERIODS = [1, 3, 6, 12];

    /** @var Collection<int, Account> */
    private Collection $accounts;

    /** @var array<int, list<array<string, mixed>>> brokerage id => its trades */
    private array $trades = [];

    private Fx $fx;

    public function __construct()
    {
        $this->accounts = Account::query()->with('meta')->orderBy('name')->get();

        foreach ($this->accounts->where('type', AccountType::Security->value) as $broker) {
            $this->trades[$broker->id] = Positions::tradesOf($broker);
        }

        $this->fx = Fx::for($this->accounts->pluck('ccy')->all());
    }

    /**
     * Every account's figure on a day, each in its own currency and in the base one.
     *
     * @return array<string, mixed>
     */
    public function on(string $day): array
    {
        $zero = BigDecimal::zero();
        $totals = ['cash' => $zero, 'cards' => $zero, 'value' => $zero, 'cost' => $zero];
        $unpriced = 0;
        $unconverted = [];

        $balances = AccountBalance::forAccounts($this->accounts->whereIn('type', [
            AccountType::Cash->value, AccountType::Card->value,
        ]), $day);

        $cash = [];

        foreach ($this->accounts->whereIn('id', array_keys($balances)) as $account) {
            $balance = $balances[$account->id];

            if (BigDecimal::of($balance)->isZero()) {
                continue;
            }

            $base = $this->fx->toBase($balance, $account->ccy, $day);

            if ($base === null) {
                $unconverted[$account->ccy] = true;
            } else {
                $key = $account->type === AccountType::Card->value ? 'cards' : 'cash';
                $totals[$key] = $totals[$key]->plus($base);
            }

            $cash[] = [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'ccy' => $account->ccy,
                'balance' => $balance,
                'base' => $base === null ? null : (string) $base,
            ];
        }

        $brokerages = [];

        foreach ($this->accounts->where('type', AccountType::Security->value) as $broker) {
            $held = array_filter(
                Positions::fromTrades(array_values(array_filter(
                    $this->trades[$broker->id],
                    fn (array $trade) => $trade['date'] <= $day
                ))),
                fn (array $position) => $position['open']
            );

            if ($held === []) {
                continue;
            }

            $prices = Price::latestFor(array_keys($held), $day);
            $value = $cost = $zero;

            foreach ($held as $symbol => $position) {
                $price = $prices[$symbol] ?? null;
                $cost = $cost->plus($position['cost']);

                if ($price === null || $price->ccy !== $broker->ccy) {
                    $unpriced++;
                    $value = $value->plus($position['cost']);

                    continue;
                }

                $value = $value->plus(BigDecimal::of($position['quantity'])->multipliedBy($price->close));
            }

            $value = $value->toScale(4, RoundingMode::HalfUp);
            $valueBase = $this->fx->toBase((string) $value, $broker->ccy, $day);
            $costBase = $this->fx->toBase((string) $cost, $broker->ccy, $day);

            if ($valueBase === null) {
                $unconverted[$broker->ccy] = true;
            } else {
                $totals['value'] = $totals['value']->plus($valueBase);
                $totals['cost'] = $totals['cost']->plus($costBase);
            }

            $brokerages[] = [
                'id' => $broker->id,
                'name' => $broker->name,
                'ccy' => $broker->ccy,
                'value' => (string) $value,
                'cost' => (string) $cost->toScale(4),
                'value_base' => $valueBase === null ? null : (string) $valueBase,
                'cost_base' => $costBase === null ? null : (string) $costBase,
                'unrealised' => (string) $value->minus($cost)->toScale(4),
                'unrealised_base' => $valueBase === null ? null : (string) $valueBase->minus($costBase),
            ];
        }

        $net = $totals['cash']->plus($totals['cards'])->plus($totals['value']);

        // Four places throughout, the amount column's, so a zero reads 0.0000 as a balance does.
        $money = fn (BigDecimal $value) => (string) $value->toScale(4);

        return [
            'date' => $day,
            'net_worth' => $money($net),
            'cash' => $money($totals['cash']),
            'cards' => $money($totals['cards']),
            'value' => $money($totals['value']),
            'cost' => $money($totals['cost']),
            'unrealised' => $money($totals['value']->minus($totals['cost'])),
            'accounts' => $cash,
            'brokerages' => $brokerages,
            'unpriced' => $unpriced,
            'unconverted' => array_keys($unconverted),
        ];
    }

    /**
     * A snapshot at the end of every $months-month period back from today, and today's for
     * the period still running. Periods count from January, so a 12-month history is year
     * ends and a 3-month one quarter ends.
     *
     * Counted back from today rather than forward from the first transaction, because each
     * point is a full recomputation: walking the whole ledger answers a question about the
     * last few months in proportion to how old the data is, and discards all but the points
     * asked for. The first transaction is still the floor, so a ledger six weeks old does not
     * gain a run of empty months before it. $limit bounds the period ends, not the points,
     * so the caller keeps the most recent.
     *
     * @return list<array<string, string>>
     */
    public function history(int $months, Carbon $today, ?int $limit = null): array
    {
        $first = Transaction::query()->min('date');

        if ($first === null) {
            return [];
        }

        $points = [];
        $todayString = $today->toDateString();
        $cursor = $today->copy()->startOfYear();

        // The last period end at or before today, so the count ends on a period boundary
        // rather than drifting by however many months into the year today falls.
        while ($cursor->copy()->addMonthsNoOverflow($months)->subDay()->toDateString() < $todayString) {
            $cursor->addMonthsNoOverflow($months);
        }

        for (; $cursor->copy()->subDay()->toDateString() >= $first; $cursor = $cursor->copy()->subMonthsNoOverflow($months)) {
            $points[] = $cursor->copy()->subDay()->toDateString();
        }

        if ($limit !== null && count($points) > $limit) {
            $points = array_slice($points, -$limit);
        }

        // Counted back from today, so newest first until here. Oldest first is the
        // contract: a caller reading the series plots or compares it in that order.
        $points = array_reverse($points);

        $points[] = $todayString;

        return array_map(function (string $day) {
            $snapshot = $this->on($day);

            return array_intersect_key($snapshot, array_flip(['date', 'net_worth', 'cash', 'cards', 'value', 'cost']));
        }, $points);
    }
}
