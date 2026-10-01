<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every year from the first transaction to this one, each read the way the other reports
 * read it, in the base currency: the cash flow by charge date, net worth at each year end,
 * and what the brokerages did.
 *
 * Built from one reading of each source for all the years, not one per year: the page sends
 * them all, so the year picker is the browser's and costs no visit, and ten years read one at
 * a time would be ten cash-flow reports.
 *
 * By charge date, as the categories page reads spending: a year's review is what was bought
 * in it, and by due date December's purchases would be January's.
 */
class YearReview
{
    /** The categories named on their own; the rest are one line. */
    public const TOP_CATEGORIES = 8;

    public const TOP_PURCHASES = 10;

    /**
     * A spend is one of the year's biggest only at a fiftieth of its spending or above: a
     * plain top ten filled a quiet year's list with the rent and the cash machine, and a
     * hundredth still let the cash machine in.
     */
    public const PURCHASE_SHARE = 50;

    /** A spend made this many times in a year is a bill and not one of its biggest spends. */
    public const PURCHASE_REPEATS = 3;

    private Fx $fx;

    public function __construct(private Carbon $today)
    {
        $this->fx = Fx::for(Account::query()->distinct()->pluck('ccy')->all());
    }

    /**
     * @return array{years: array<int, array<string, mixed>>, unconverted: list<string>}
     */
    public function all(): array
    {
        // From the first whole year, as every page that counts back starts: see Ledger.
        $start = Ledger::start();

        if ($start === null) {
            return ['years' => [], 'unconverted' => []];
        }

        $thisYear = $this->today->year;
        $firstYear = (int) substr($start, 0, 4);
        $years = range($firstYear, $thisYear);

        $flow = $this->cashFlow($firstYear);
        $worth = $this->netWorth($years);
        $realised = $this->realised($years);
        $spending = $this->spendingRows($firstYear);
        $counts = Transaction::query()
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->selectRaw('YEAR(`date`) AS year, COUNT(*) AS row_count')
            ->groupBy('year')
            ->pluck('row_count', 'year');

        $built = [];

        foreach ($years as $year) {
            $built[$year] = $this->year(
                $year,
                $flow['months'][$year] ?? [],
                $worth,
                $realised,
                $spending[$year] ?? [],
                (int) ($counts[$year] ?? 0),
                array_key_exists($year - 1, $built) ? ($flow['months'][$year - 1] ?? []) : null,
            );
        }

        return ['years' => array_reverse($built, true), 'unconverted' => $flow['unconverted']];
    }

    /**
     * @param  list<array<string, mixed>>  $months  the cash flow's months of this year
     * @param  array<string, array<string, mixed>>  $worth  net worth snapshot by day
     * @param  array<int, BigDecimal>  $realised  realised gains by year, in the base currency
     * @param  list<object>  $rows  this year's spending rows, each with its base figure
     * @param  list<array<string, mixed>>|null  $previous  the cash flow's months of the year before
     * @return array<string, mixed>
     */
    private function year(int $year, array $months, array $worth, array $realised, array $rows, int $count, ?array $previous): array
    {
        $zero = BigDecimal::zero();
        $partial = $year === $this->today->year;
        $from = "{$year}-01-01";
        $to = $partial ? $this->today->toDateString() : "{$year}-12-31";
        $sum = fn (string $key) => array_reduce($months, fn (BigDecimal $total, array $month) => $total->plus($month[$key]), $zero);

        $income = $sum('income');
        $spending = $sum('spending');
        $net = $income->minus($spending);
        $invested = $sum('invested');
        $dividends = $sum('dividend');

        // Each category over the year, the months' breakdowns added up.
        $byCategory = [];

        foreach ($months as $month) {
            foreach ($month['categories'] as $category) {
                $key = (string) ($category['id'] ?? 'none');
                $byCategory[$key] ??= ['id' => $category['id'], 'name' => $category['name'] ?? 'No category', 'amount' => $zero];
                $byCategory[$key]['amount'] = $byCategory[$key]['amount']->plus($category['amount']);
            }
        }

        uasort($byCategory, fn (array $a, array $b) => $b['amount']->compareTo($a['amount']));

        // What the year is compared with: the year before, whole, or for the year still running
        // the same months of it -- nine months against twelve read as spending down by half.
        // Every category of it, not only the ones it named, so one that was ninth then and
        // second now still has a figure.
        $compare = $previous === null ? null : $this->compare($previous, $partial ? count($months) : 12, $year - 1);
        $before = $compare['categories'] ?? [];
        $named = array_slice($byCategory, 0, self::TOP_CATEGORIES, true);
        $rest = array_reduce(array_slice($byCategory, self::TOP_CATEGORIES), fn (BigDecimal $t, array $c) => $t->plus($c['amount']), $zero);

        $categories = array_values(array_map(fn (array $category, string $key) => [
            'id' => $category['id'],
            'name' => $category['name'],
            'amount' => self::money($category['amount']),
            'share' => self::share($category['amount'], $spending),
            'previous' => isset($before[$key]) ? self::money($before[$key]) : null,
        ], $named, array_keys($named)));

        // The months, best and worst by what was kept.
        $monthRows = array_map(fn (array $month) => [
            'month' => $month['month'],
            'income' => self::money(BigDecimal::of($month['income'])),
            'spending' => self::money(BigDecimal::of($month['spending'])),
            'net' => self::money(BigDecimal::of($month['net'])),
        ], $months);
        $ranked = $monthRows;
        usort($ranked, fn (array $a, array $b) => BigDecimal::of($b['net'])->compareTo($a['net']));

        // Net worth at the year end before and at this one's, or today's for the year running.
        $start = $worth[($year - 1).'-12-31'];
        $end = $worth[$to];
        $valueChange = BigDecimal::of($end['value'])->minus($start['value']);
        // What the holdings earned: their value's change less the money put into them, and
        // the dividends they paid on top.
        $result = $valueChange->minus($invested);

        return [
            'year' => $year,
            'from' => $from,
            'to' => $to,
            'partial' => $partial,
            'income' => self::money($income),
            'spending' => self::money($spending),
            'net' => self::money($net),
            'savings_rate' => self::share($net, $income),
            'months' => $monthRows,
            'best' => $ranked[0] ?? null,
            'worst' => count($ranked) > 1 ? end($ranked) : null,
            'categories' => $categories,
            'other_categories' => self::money($rest),
            'compare' => $compare === null ? null : array_diff_key($compare, ['categories' => true]),
            'net_worth' => [
                'start' => $start['net_worth'],
                'end' => $end['net_worth'],
                'change' => self::money(BigDecimal::of($end['net_worth'])->minus($start['net_worth'])),
                'cash' => $end['cash'],
                'value' => $end['value'],
            ],
            'investing' => [
                'invested' => self::money($invested),
                'dividends' => self::money($dividends),
                'realised' => self::money($realised[$year] ?? $zero),
                'value_start' => $start['value'],
                'value_end' => $end['value'],
                'result' => self::money($result),
                'total' => self::money($result->plus($dividends)),
            ],
            'purchases' => $this->purchases($rows, $floor = $spending->dividedBy(self::PURCHASE_SHARE, 4, RoundingMode::HalfUp)),
            'purchase_floor' => self::money($floor),
            'facts' => $this->facts($rows, $count, $from, $to),
        ];
    }

    /**
     * The year before's figures over its first $months months -- all twelve for a year that
     * is over -- for the year being read to be put against.
     *
     * @param  list<array<string, mixed>>  $months
     * @return array{label: string, income: string, spending: string, savings_rate: ?string, categories: array<string, BigDecimal>}
     */
    private function compare(array $months, int $count, int $year): array
    {
        $zero = BigDecimal::zero();
        $span = array_slice($months, 0, $count);
        $income = array_reduce($span, fn (BigDecimal $t, array $m) => $t->plus($m['income']), $zero);
        $spending = array_reduce($span, fn (BigDecimal $t, array $m) => $t->plus($m['spending']), $zero);
        $categories = [];

        foreach ($span as $month) {
            foreach ($month['categories'] as $category) {
                $key = (string) ($category['id'] ?? 'none');
                $categories[$key] = ($categories[$key] ?? $zero)->plus($category['amount']);
            }
        }

        $label = (string) $year;

        if ($count < 12) {
            $last = Carbon::create($year, $count, 1);
            $label = $count === 1 ? $last->format('M Y') : 'Jan–'.$last->format('M Y');
        }

        return [
            'label' => $label,
            'income' => self::money($income),
            'spending' => self::money($spending),
            'savings_rate' => self::share($income->minus($spending), $income),
            'categories' => $categories,
        ];
    }

    /**
     * The cash flow by charge date in the base currency, every month since the first year's
     * January, grouped by year.
     *
     * @return array{months: array<int, list<array<string, mixed>>>, unconverted: list<string>}
     */
    private function cashFlow(int $firstYear): array
    {
        $count = ($this->today->year - $firstYear) * 12 + $this->today->month;
        $combined = CashFlow::combined($this->today, $count, onDueDate: false);
        $months = [];

        foreach ($combined['report']['months'] ?? [] as $month) {
            $months[(int) substr($month['month'], 0, 4)][] = $month;
        }

        return ['months' => $months, 'unconverted' => $combined['unconverted']];
    }

    /**
     * Net worth on every year end and today, in one reading.
     *
     * @param  list<int>  $years
     * @return array<string, array<string, mixed>>
     */
    private function netWorth(array $years): array
    {
        $days = array_map(fn (int $year) => ($year - 1).'-12-31', $years);
        $days[] = $this->today->toDateString();

        // Every one a month end but the last, as AccountBalance::seriesFor() needs.
        $days = array_values(array_unique(array_filter($days, fn (string $day) => $day <= $this->today->toDateString())));

        return array_combine($days, (new NetWorth)->onMany($days));
    }

    /**
     * What sales realised in each year, in the base currency at the year end's rate: the
     * trades replayed to each year end, less what the year before had realised.
     *
     * @param  list<int>  $years
     * @return array<int, BigDecimal>
     */
    private function realised(array $years): array
    {
        $byYear = [];

        foreach (Account::query()->where('type', AccountType::Security->value)->get() as $broker) {
            $trades = Positions::tradesOf($broker);
            $before = BigDecimal::zero();

            foreach ($years as $year) {
                $to = $year === $this->today->year ? $this->today->toDateString() : "{$year}-12-31";
                $upTo = array_values(array_filter($trades, fn (array $trade) => $trade['date'] <= $to));
                $total = array_reduce(
                    Positions::fromTrades($upTo),
                    fn (BigDecimal $sum, array $position) => $sum->plus($position['realised']),
                    BigDecimal::zero()
                );

                $inBase = $this->fx->toBase((string) $total->minus($before), $broker->ccy, $to);
                $byYear[$year] = ($byYear[$year] ?? BigDecimal::zero())->plus($inBase ?? BigDecimal::zero());
                $before = $total;
            }
        }

        return $byYear;
    }

    /**
     * Every spending row since the first year, as the cash flow counts spending, by charge
     * date, with its figure in the base currency at its own day's rate. A row in a currency
     * with no rate that day is left out, as the cash flow leaves it.
     *
     * @return array<int, list<object>>
     */
    private function spendingRows(int $firstYear): array
    {
        $rows = Transaction::query()
            ->whereHas('account', fn (Builder $q) => $q->whereIn('type', [AccountType::Cash->value, AccountType::Card->value]))
            ->whereIn('status', TransactionStatus::countingTowardBalance())
            ->tap(fn (Builder $q) => CashFlow::whereSpends($q))
            ->whereBetween('date', ["{$firstYear}-01-01", $this->today->toDateString()])
            ->toBase()
            ->get(['id', 'account_id', 'category_id', 'date', 'description', 'amount']);

        $bags = CashFlow::bagsOf($rows->pluck('id')->all());
        $accounts = DB::table('accounts')->get(['id', 'name', 'ccy'])->keyBy('id');
        $categories = DB::table('categories')->pluck('name', 'id');
        $byYear = [];

        foreach ($rows as $row) {
            $account = $accounts[$row->account_id];
            $figure = (string) ($bags[$row->id]['card_amount'] ?? $row->amount);
            $base = $this->fx->toBase($figure, $account->ccy, $row->date);

            if ($base === null) {
                continue;
            }

            $row->base = $base;
            $row->figure = $figure;
            $row->ccy = $account->ccy;
            $row->account = $account->name;
            $row->category = $categories[$row->category_id] ?? null;
            $byYear[(int) substr($row->date, 0, 4)][] = $row;
        }

        return $byYear;
    }

    /**
     * @param  list<object>  $rows
     * @param  BigDecimal  $floor  the least a spend may be to be listed
     * @return list<array<string, mixed>>
     */
    private function purchases(array $rows, BigDecimal $floor): array
    {
        // Not a payment the year makes again and again -- the rent, "INSTALMENT 19 OF 36" and
        // its eleven siblings -- which is a bill, not a spend, and filled whole years' lists.
        // The same description with its figures taken out, three times or more.
        $habit = fn (object $row) => trim(preg_replace('/[\d.,@#*\/-]+/', ' ', mb_strtolower((string) $row->description)));
        $times = array_count_values(array_map($habit, $rows));

        $rows = array_filter($rows, fn (object $row) => $row->base->isGreaterThanOrEqualTo($floor)
            && $times[$habit($row)] < self::PURCHASE_REPEATS);
        usort($rows, fn (object $a, object $b) => $b->base->compareTo($a->base));

        return array_map(fn (object $row) => [
            'id' => $row->id,
            'date' => $row->date,
            'description' => $row->description,
            'account' => $row->account,
            'category' => $row->category,
            'amount' => self::money($row->base),
            'native' => $row->ccy === Fx::BASE->value ? null : ['amount' => self::money(BigDecimal::of($row->figure)), 'ccy' => $row->ccy],
        ], array_slice($rows, 0, self::TOP_PURCHASES));
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, mixed>
     */
    private function facts(array $rows, int $count, string $from, string $to): array
    {
        $days = array_values(array_unique(array_map(fn (object $row) => $row->date, $rows)));
        sort($days);

        // The longest run of days with no spending, the year's edges counted as its bounds.
        $longest = ['days' => 0, 'from' => null, 'to' => null];
        $previous = Carbon::parse($from)->subDay();

        foreach ([...$days, Carbon::parse($to)->addDay()->toDateString()] as $day) {
            $gap = (int) $previous->diffInDays(Carbon::parse($day)) - 1;

            if ($gap > $longest['days']) {
                $longest = [
                    'days' => $gap,
                    'from' => $previous->copy()->addDay()->toDateString(),
                    'to' => Carbon::parse($day)->subDay()->toDateString(),
                ];
            }

            $previous = Carbon::parse($day);
        }

        // Where the money went most often, the description as typed, in any case.
        $merchants = [];

        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row->description));

            if ($key === '') {
                continue;
            }

            $merchants[$key] ??= ['description' => $row->description, 'count' => 0, 'amount' => BigDecimal::zero()];
            $merchants[$key]['count']++;
            $merchants[$key]['amount'] = $merchants[$key]['amount']->plus($row->base);
        }

        uasort($merchants, fn (array $a, array $b) => [$b['count'], $b['amount']] <=> [$a['count'], $a['amount']]);
        $top = reset($merchants) ?: null;

        return [
            'transactions' => $count,
            'spending_days' => count($days),
            'days' => (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1,
            'top_merchant' => $top === null ? null : [...$top, 'amount' => self::money($top['amount'])],
            'longest_no_spend' => $longest,
        ];
    }

    /** A share for reading, as a percentage to one place, or null with nothing to share. */
    private static function share(BigDecimal $part, BigDecimal $whole): ?string
    {
        return $whole->isPositive()
            ? (string) $part->multipliedBy(100)->dividedBy($whole, 1, RoundingMode::HalfUp)
            : null;
    }

    private static function money(BigDecimal $value): string
    {
        return (string) $value->toScale(4, RoundingMode::HalfUp);
    }
}
