<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Enums\Frequency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Price;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;

/**
 * What the Home page asks to be dealt with, worst first. Each item names where it is
 * fixed, so the list is a set of links rather than a report.
 */
class Attention
{
    /** Prices older than this, in days, while shares are held, are called stale. */
    public const STALE_PRICES_DAYS = 3;

    /** How far ahead a yearly recurring is worth naming, in days. */
    public const YEARLY_SOON_DAYS = 90;

    public const PREVIEW_ROWS = 3;

    /**
     * @param  iterable<array<string, mixed>>  $cash  Home's cash accounts, with balance
     * @param  iterable<array<string, mixed>>  $statements  Home's unsettled statements
     * @return list<array{level: string, icon: string, message: string, link: array{path: string, data?: array<string, mixed>, broker?: int}, title?: string, detail?: string, when?: string, amount?: string, ccy?: string, rows?: list<array<string, string|null>>, more?: int}>
     */
    public static function items(Carbon $today, iterable $cash, iterable $statements, Forecast $forecast, bool $holdsShares): array
    {
        $day = $today->toDateString();
        $items = [];

        foreach ($statements as $statement) {
            if ($statement['due_date'] < $day) {
                $items[] = self::item('negative', 'credit_card_off',
                    "{$statement['card']['name']}: ".self::money($statement['owed'])." {$statement['card']['ccy']} was due on {$statement['due_date']}",
                    '/transactions', ['filter' => ['account_id' => $statement['card']['id'], 'due_date' => $statement['due_date']]]);
            }
        }

        $belowZero = [];

        foreach ($cash as $account) {
            if (BigDecimal::of($account['balance'])->isNegative()) {
                $belowZero[$account['id']] = true;
                $items[] = self::item('negative', 'trending_down',
                    "{$account['name']} is below zero: ".self::money($account['balance'])." {$account['ccy']}",
                    '/transactions', ['filter' => ['account_id' => $account['id']]]);
            }
        }

        // Only an account above zero today: one below it is named just above.
        foreach ($forecast->projection() as $section) {
            foreach ($section['accounts'] as $account) {
                if (! isset($belowZero[$account['id']]) && BigDecimal::of($account['native']['lowest'])->isNegative()) {
                    $items[] = self::item('warning', 'query_stats',
                        "{$account['name']} is forecast to go below zero on {$account['lowest']['date']}",
                        '/forecast');
                }
            }
        }

        foreach ($forecast->warnings() as $warning) {
            $items[] = self::item('warning', 'warning_amber', $warning, '/forecast');
        }

        $pendingQuery = Transaction::query()
            ->where('status', TransactionStatus::Pending->value)
            ->where('date', '<=', $day)
            ->whereHas('account', fn ($q) => $q->where('type', '!=', AccountType::Security->value));

        $pending = (clone $pendingQuery)->count();

        if ($pending > 0) {
            $rows = $pendingQuery->with('account')->orderBy('date')->orderBy('id')->limit(self::PREVIEW_ROWS)->get()
                ->map(fn (Transaction $t) => [
                    'date' => Carbon::parse($t->date)->toDateString(),
                    'description' => $t->description,
                    'account' => $t->account?->name,
                    'amount' => (string) BigDecimal::of($t->amount)->multipliedBy(
                        TransactionType::from($t->type)->movesBalanceOn(AccountType::from($t->account->type)) ?: 1
                    ),
                    'ccy' => $t->ccy,
                ])->all();

            $items[] = self::item('warning', 'pending_actions',
                $pending === 1 ? '1 pending transaction is due and not posted yet' : "{$pending} pending transactions are due and not posted yet",
                '/transactions', ['filter' => ['status' => TransactionStatus::Pending->value, 'date_to' => $day]],
                ['title' => 'Pending, not posted', 'rows' => $rows, 'more' => max(0, $pending - count($rows))]);
        }

        // Recorded up to a refused occurrence and stopped there; see RecurringPayments.
        $yearlySoon = $today->copy()->addDays(self::YEARLY_SOON_DAYS)->toDateString();

        foreach (RecurringTransaction::query()->with('account')->where('active', true)->orderBy('description')->get() as $rule) {
            $next = $rule->nextDate();

            if ($next === null) {
                continue;
            }

            if ($next < $day) {
                $items[] = self::item('warning', 'event_repeat',
                    "Recurring [{$rule->description}] has not been recorded since {$next}", '/recurring');

                continue;
            }

            // A yearly rule this close, and only a yearly one: a monthly rule is always
            // inside any window worth naming, so listing them would say nothing on any day
            // and drown the notices that are about this week. A year is the one bill whose
            // coming due is worth being told about before it lands.
            if ($rule->frequency === Frequency::Yearly->value && $next <= $yearlySoon) {
                $items[] = self::yearlyItem($rule, $next, $today);
            }
        }

        if ($holdsShares) {
            $latest = Price::where('source', 'yahoo')->max('updated_at');
            $age = $latest === null ? null : (int) Carbon::parse($latest)->startOfDay()->diffInDays($today);

            if ($age === null || $age > self::STALE_PRICES_DAYS) {
                $items[] = self::item('warning', 'update',
                    $age === null ? 'Prices have never been fetched' : "Prices were last fetched {$age} days ago",
                    '/positions');
            }
        }

        return $items;
    }

    /**
     * The yearly rules due within YEARLY_SOON_DAYS, as items() words them, for the phone's
     * simple page, which shows these and none of the rest: a phone is not where a pending row
     * is posted or a stale price fetched, and a yearly bill is the one worth seeing coming.
     *
     * @return list<array<string, mixed>>
     */
    public static function yearlySoon(Carbon $today): array
    {
        $day = $today->toDateString();
        $soon = $today->copy()->addDays(self::YEARLY_SOON_DAYS)->toDateString();

        return RecurringTransaction::query()->with('account')
            ->where('active', true)
            ->where('frequency', Frequency::Yearly->value)
            ->orderBy('description')
            ->get()
            ->map(fn (RecurringTransaction $rule) => [$rule, $rule->nextDate()])
            ->filter(fn (array $pair) => $pair[1] !== null && $pair[1] >= $day && $pair[1] <= $soon)
            ->map(fn (array $pair) => self::yearlyItem($pair[0], $pair[1], $today))
            ->values()
            ->all();
    }

    private static function yearlyItem(RecurringTransaction $rule, string $next, Carbon $today): array
    {
        $days = $today->diffInDays(Carbon::parse($next), false);

        return self::item('warning', 'event_repeat',
            sprintf(
                'Yearly recurring [%s], %s %s, is due on %s, in %d days',
                $rule->description,
                self::money($rule->amount),
                $rule->ccy,
                $next,
                $days,
            ),
            '/recurring', [],
            ['title' => $rule->description, 'detail' => "Yearly · due {$next} · {$rule->account?->name}",
                'when' => 'in '.$days.' days',
                'amount' => (string) BigDecimal::of($rule->amount)->multipliedBy(
                    TransactionType::from($rule->type)->movesBalanceOn(AccountType::Cash) ?: -1
                ), 'ccy' => $rule->ccy]);
    }

    /** For reading, as the page's money formatter shows it: grouped, two places. */
    private static function money(string $amount): string
    {
        $value = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
        [$whole, $cents] = explode('.', (string) $value->abs());

        return ($value->isNegative() ? '-' : '').strrev(implode(',', str_split(strrev($whole), 3))).'.'.$cents;
    }

    /** @return array{level: string, icon: string, message: string, link: array<string, mixed>} */
    private static function item(string $level, string $icon, string $message, string $path, array $data = [], array $parts = []): array
    {
        return ['level' => $level, 'icon' => $icon, 'message' => $message, 'link' => ['path' => $path, ...($data === [] ? [] : ['data' => $data])], ...$parts];
    }
}
