<?php

namespace App\Support;

use App\Enums\Frequency;
use App\Enums\TransactionType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;

/**
 * Which of a pair of years of transactions look like a recurring rule, and which existing
 * rules the history no longer agrees with.
 *
 * Rows in, findings out, with no query and no clock: the caller reads the rows and passes
 * the day to judge against, so the whole of this is arithmetic and can be tested on a
 * hand-built list.
 *
 * Two matchers, one per direction, because the amount means a different thing to each. A
 * deposit's figure is set by whatever pays it -- a raise, a thirteenth month, a rate -- so
 * it is matched on *when* it arrives and the amount offered is the newest one. Everything
 * else is matched on the amount, because a subscription's amount is its identity: a charge
 * that changes figure is a different subscription, and pinning it to the newest would turn
 * a price rise into a new bill.
 *
 * A rule whose payments are not on a clean cadence is not reported as finished. One missed
 * month is a missed month: the rule paid 13 times since, and calling that finished reads as
 * a licence to delete a subscription that is working. Only the absence of recent payments
 * says a rule has stopped, and that is what 'stopped' means here.
 *
 * Nothing here writes. A rule created from a finding starts on the next occurrence at or
 * after the day it was asked about, never on the first one in the history, because
 * RecurringPayments::record() writes a pending row for every occurrence since start_date
 * and the ledger already holds those months as posted rows.
 */
class RecurringPatterns
{
    /**
     * Deposits never offered as a new rule, whatever their cadence says.
     *
     * CREDIT INTEREST is here because the cadence is real -- 23 payments, every month -- and
     * 0.56 a month is not worth a rule: the pending row it writes is noise, and a forecast
     * line for a few cents is worse than none. A name and not a test, because no tolerance
     * separates it from a salary that rose 5%: both are monthly with small variation. The
     * day-of-month test rejects this one on its own, its credits landing across the 27th to
     * the 31st rather than on a day of the month, so this is a decision about one credit
     * and it is why nothing here needs a floor.
     *
     * It hides a new rule, not an existing one: a rule somebody wrote for it is still their
     * rule, and this has no business overruling it.
     *
     * @var list<string>
     */
    private const IGNORED_DEPOSITS = ['CREDIT INTEREST'];

    /**
     * Occurrences needed before a pattern is offered at all.
     *
     * A month is short enough that two payments prove nothing and three is what it takes. A
     * year is long enough that two of them are hardly a coincidence -- and a 24-month window
     * holds only two, so asking for three would mean a yearly subscription could never be
     * found at any window length, which is how five of them sat in the ledger unseen: ADOBE
     * CC 1 YEAR, GO DADDY, MCAFEE, NINTENDO ONLINE 1 YEAR and OFFICE 365 1 YEAR, each one a
     * domain registration or a year of a game service, paid on the same day every year.
     *
     * @var array<string, int>
     */
    private const MIN_OCCURRENCES = ['monthly' => 3, 'yearly' => 2];

    /** A month either side of the calendar's, and a year either side likewise. */
    private const MONTHLY_GAP = [25, 35];

    private const YEARLY_GAP = [350, 380];

    /**
     * How far the day of the month may wander, per cadence. A rule fires on one day of the
     * month, so a history landing further apart than this is not that rule: the 25th to the
     * 29th is (spread 4, and a real monthly payment), the 3rd to the 31st is not. A year's
     * renewal drifts further, the 17th one year and the 22nd the next, as PLAYSTATION®PLUS
     * did and was missed for; seven days is still one date, and a month away is not.
     *
     * @var array<string, int>
     */
    private const DAY_SPREAD = ['monthly' => 4, 'yearly' => 7];

    /** Days without a payment after which a rule is treated as finished, per cadence. */
    private const STOPPED_AFTER = ['monthly' => 70, 'yearly' => 730];

    /**
     * One finding per rule, and one per pattern no rule covers. A rule gets its own finding
     * even where two rules share an account and a description, because a key built from
     * those two would drop one of them out of the report -- and out of anything written.
     *
     * @param  list<array{account_id: int, account: string, type: string, description: string, ccy: string, date: string, amount: string, category_id: ?int}>  $rows
     * @param  list<array{id: int, account_id: int, account: string, description: string, amount: string, frequency: string, start_date: string}>  $rules
     * @return list<array<string, mixed>>
     */
    public static function find(array $rows, array $rules, string $today): array
    {
        $groups = self::group($rows);
        $detected = [];
        $covered = [];

        foreach ($groups as $key => $group) {
            $found = self::detect($group, $today);

            if ($found !== null) {
                $detected[$key] = $found;
            }
        }

        $findings = [];

        foreach ($rules as $rule) {
            $key = self::key((int) $rule['account_id'], $rule['description']);
            $covered[$key] = true;
            $summary = isset($groups[$key]) ? self::summarise($groups[$key]) : self::blank($rule);
            $found = $detected[$key] ?? null;

            if (self::hasStopped($summary, $rule, $today)) {
                $findings[] = self::verdict($summary, $rule, ['stopped']);

                continue;
            }

            // Recent, and not on a cadence a rule can express. Nothing to change and nothing
            // to say beyond the count, which is a different thing from having stopped.
            $findings[] = $found === null
                ? self::verdict($summary, $rule, [])
                : self::verdict($found, $rule, self::flags($found, $rule));
        }

        foreach ($detected as $key => $found) {
            // The named credits are kept out here rather than in detect(), so that a rule
            // somebody wrote for one is still compared with its own history and reported as
            // agreeing with it rather than as nothing found.
            if (! isset($covered[$key]) && ! self::ignored($found)) {
                $findings[] = self::verdict($found, null, []);
            }
        }

        return $findings;
    }

    /**
     * Groups in date order, oldest first. Sorted here and not in the readers, because a
     * reader that took the last row of an unsorted group as the newest payment would read
     * whichever row the query happened to return last: one odd charge after the last
     * subscription payment would become the figure the subscription is currently at.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private static function group(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[self::key((int) $row['account_id'], (string) $row['description'])][] = $row;
        }

        foreach ($groups as &$group) {
            usort($group, fn (array $a, array $b) => $a['date'] <=> $b['date']);
        }

        return $groups;
    }

    private static function key(int $accountId, string $description): string
    {
        return $accountId.'|'.self::name($description);
    }

    /**
     * A description as the payment is known across its history: spaces collapsed, and a
     * trailing price with its currency cut off. FLICKR PRO bills as "1 YEAR 79.99 USD" one
     * year and "1 YEAR 96 USD" the next, with one space or two; kept whole, each year was a
     * group of one, and a subscription paid ten years running was never found.
     */
    public static function name(string $description): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $description));

        return trim((string) preg_replace('/\s+\d+(?:[.,]\d+)?\s*[A-Z]{3}$/u', '', $name)) ?: $name;
    }

    /**
     * The pattern, or null when the group holds nothing a rule can be written from. This is
     * the test that has to pass for anything to be *offered* or to be *compared*, so it is
     * deliberately strict: a gap or a day that wanders ends it.
     *
     * @param  list<array<string, mixed>>  $group
     * @return array<string, mixed>|null
     */
    private static function detect(array $group, string $today): ?array
    {
        $summary = self::summarise($group);

        // A yearly subscription's price is set at each renewal, so its figure changing is
        // the pattern, not a break in it: at the current figure only, a yearly service whose
        // price rose last renewal is one payment, and never found. A month's is still cut
        // to its current figure, where one odd charge must not read as the price.
        $every = array_values(array_unique(array_column($group, 'date')));
        $run = $summary['deposit'] || self::cadence($every) === 'yearly' ? $group : self::atCurrentFigure($group);

        // One date per occasion, so a thirteenth month paid on the same day as the salary
        // is not a payment that arrives twice. The count below still counts both, because
        // both are payments.
        $dates = array_values(array_unique(array_column($run, 'date')));
        $cadence = self::cadence($dates);

        if ($cadence === null || count($run) < self::MIN_OCCURRENCES[$cadence]) {
            return null;
        }

        $day = self::modalDay($dates);

        if ($day['spread'] > self::DAY_SPREAD[$cadence]) {
            return null;
        }

        $category = self::category($run);
        $newest = $run[count($run) - 1];

        return [
            ...$summary,
            'amount' => $newest['amount'],
            'card_amount' => $newest['card_amount'] ?? null,
            'cadence' => $cadence,
            'occurrences' => count($run),
            'first' => $run[0]['date'],
            'last' => $newest['date'],
            'day' => $day['day'],
            'tied_days' => $day['tied'],
            'day_spread' => $day['spread'],
            'category_id' => $category['id'],
            'category_occurrences' => $category['occurrences'],
            // Null when the day is ambiguous, and the finding is then one that cannot be
            // scheduled: the ambiguity is the finding's own reason.
            'start_date' => $day['day'] === null
                ? null
                : self::nextOccurrence($cadence, $run, $day['day'], $today),
        ];
    }

    /**
     * What a group holds, with no opinion about whether it is a pattern. This is what a rule
     * is judged on when the group is not one, so that a rule with recent payments is never
     * reported as finished on the strength of a cadence it does not have to keep.
     *
     * @param  list<array<string, mixed>>  $group  in date order, as group() leaves it
     * @return array<string, mixed>
     */
    private static function summarise(array $group): array
    {
        $first = $group[0];
        $newest = $group[count($group) - 1];

        return [
            'account_id' => (int) $first['account_id'],
            'account' => $first['account'],
            // The name the payments share, so a rule written from it is not one year's price.
            'description' => self::name($newest['description']),
            'type' => $first['type'],
            'ccy' => $first['ccy'],
            'deposit' => $first['type'] === TransactionType::Deposit->value,
            'amount' => $newest['amount'],
            'card_amount' => $newest['card_amount'] ?? null,
            'cadence' => null,
            'occurrences' => count($group),
            'first' => $first['date'],
            'last' => $newest['date'],
            'day' => null,
            'tied_days' => [],
            'day_spread' => 0,
            'category_id' => null,
            'category_occurrences' => 0,
            'start_date' => null,
        ];
    }

    /** A rule with no rows at all behind it, so nothing but the rule itself to show. */
    private static function blank(array $rule): array
    {
        return [
            'account_id' => (int) $rule['account_id'],
            'account' => $rule['account'],
            'description' => $rule['description'],
            'type' => null,
            'ccy' => null,
            'deposit' => false,
            'amount' => null,
            'cadence' => null,
            'occurrences' => 0,
            'first' => null,
            'last' => null,
            'day' => null,
            'tied_days' => [],
            'day_spread' => 0,
            'category_id' => null,
            'category_occurrences' => 0,
            'start_date' => null,
        ];
    }

    /**
     * The occurrences at the current figure, which is the newest one's.
     *
     * A price rise splits a subscription's history in two and the newer half is the one that
     * is being paid, so the older figure is left out. What is *not* left out is anything else
     * in between: one odd charge truncates a run that stops at the first difference, and a
     * subscription of twenty-two payments then reads as a single payment. PTCG is 78.78 every
     * month on the 15th with one 179.78 in the middle of it, and iCloud is 8.08 every month
     * on the 22nd with one 48.48 -- both invisible for the want of skipping over it.
     *
     * @param  list<array<string, mixed>>  $rows  oldest first
     * @return list<array<string, mixed>>
     */
    private static function atCurrentFigure(array $rows): array
    {
        $current = $rows[count($rows) - 1]['amount'];

        return array_values(array_filter(
            $rows,
            fn (array $row) => self::sameAmount($row['amount'], $current)
        ));
    }

    /**
     * The band that most of the gaps between consecutive payments fall in.
     *
     * A majority, not all of them. One missed month does not make a subscription something
     * else: HMVOD has nineteen gaps, eighteen of them a month and one of sixty-two days, and
     * reading that single gap as the end of the pattern left a rule that fires on the 1st
     * sitting beside a subscription that pays on the 13th -- with nothing to act on and
     * nothing to say about it. A pattern has to be mostly regular to be one, and two thirds
     * of its gaps in one band is that. A gap in neither band is not counted either way, so
     * something paid every two months is not a rule and never becomes one.
     *
     * @param  list<string>  $dates  oldest first
     */
    private static function cadence(array $dates): ?string
    {
        // One date has no interval in it, and reading it as a month would put a single
        // payment through as a monthly pattern.
        if (count($dates) < 2) {
            return null;
        }

        $gaps = [];

        foreach (array_slice($dates, 1) as $i => $date) {
            // Absolute, and asked for: Carbon 3's diffInDays is signed, so a gap read the
            // other way round is negative and every pattern looks like no pattern at all --
            // which reads as a verdict on the rules rather than as no answer.
            $gaps[] = Carbon::parse($date)->diffInDays(Carbon::parse($dates[$i]), true);
        }

        $count = function (array $band) use ($gaps): int {
            return count(array_filter($gaps, fn (int|float $gap) => self::within($gap, $band)));
        };

        $monthly = $count(self::MONTHLY_GAP);
        $yearly = $count(self::YEARLY_GAP);

        if ($monthly >= $yearly && $monthly * 3 >= count($gaps) * 2) {
            return 'monthly';
        }

        return $yearly * 3 >= count($gaps) * 2 ? 'yearly' : null;
    }

    /**
     * The most common day of the month, and how far the schedule really moves.
     *
     * The spread is taken over the days seen more than once, not over every day. One
     * payment on an odd day is one observation, and it cannot make a schedule unstable: a
     * game bought on its release day sits alongside five monthly payments on the 7th and
     * widens the range to five days, which reads as a schedule that wanders when it does not.
     *
     * Where every payment lands on a day of its own -- two yearly payments a fortnight apart,
     * say -- the newest is taken, because it is the only evidence there is about when this
     * happens now.
     *
     * And where two days are paid equally often, the first of them: a schedule that is on
     * the 27th or the 28th is a schedule, and the earlier of the two is the one a rule can be
     * written from without having to be wrong on half the months. Both are still reported,
     * so the report can say the day it settled on and the day it could have been.
     *
     * @param  list<string>  $dates  oldest first
     * @return array{day: ?int, tied: list<int>, spread: int}
     */
    private static function modalDay(array $dates): array
    {
        $counts = [];

        foreach ($dates as $date) {
            $day = (int) Carbon::parse($date)->day;
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        $common = array_keys(array_filter($counts, fn (int $n) => $n > 1)) ?: array_keys($counts);
        $most = max($counts);
        $tied = array_values(array_keys(array_filter($counts, fn (int $n) => $n === $most)));
        sort($tied);

        // Every day seen exactly once, so there is no pattern to weigh: the latest is it.
        $singles = $common === array_keys($counts) && count($counts) === count($dates);

        return [
            'day' => $singles ? (int) Carbon::parse($dates[count($dates) - 1])->day : $tied[0],
            'tied' => $singles || count($tied) === 1 ? [] : $tied,
            'spread' => max($common) - min($common),
        ];
    }

    /**
     * The first occurrence at or after $today, counted from the last payment that landed on
     * the day the rule will fire on.
     *
     * Stepping from the newest payment instead would carry whatever day that one drifted to
     * into every future occurrence: a subscription that pays on the 20th and was late once
     * on the 21st would be scheduled on the 21st for good, and a report that named day 20
     * and a start date of the 21st would be contradicting itself. That drift is not a
     * hypothesis either -- it is how all eight migrated rules came to fire on the 1st while
     * the history showed the 12th, the 20th and the 25th.
     *
     * Through Frequency, which is the same arithmetic the recorder uses, so a rule's first
     * written row cannot disagree with the rule's own schedule. And from the last such
     * payment rather than the first: a monthly rule that last paid in March and has not been
     * touched since July starts in August, not April, because the recorder writes a pending
     * row for every occurrence since the start date and those months are already posted.
     *
     * @param  list<array<string, mixed>>  $run  oldest first
     */
    private static function nextOccurrence(string $cadence, array $run, int $day, string $today): ?string
    {
        $frequency = Frequency::from($cadence);

        $on = array_values(array_filter(
            array_reverse($run),
            fn (array $row) => (int) Carbon::parse($row['date'])->day === $day
        ));

        $from = Carbon::parse($on[0]['date'] ?? $run[count($run) - 1]['date']);

        for ($n = 1; $n <= 14; $n++) {
            $date = $frequency->occurrence($from, $n)->toDateString();

            if ($date >= $today) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{id: ?int, occurrences: int}
     */
    private static function category(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            if ($row['category_id'] !== null) {
                $counts[$row['category_id']] = ($counts[$row['category_id']] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return ['id' => null, 'occurrences' => 0];
        }

        arsort($counts);

        return ['id' => (int) array_key_first($counts), 'occurrences' => (int) reset($counts)];
    }

    /**
     * @param  array<string, mixed>  $found
     * @param  array<string, mixed>  $rule
     * @return list<string>
     */
    private static function flags(array $found, array $rule): array
    {
        $flags = [];

        if (! self::sameAmount($found['amount'], $rule['amount'])) {
            $flags[] = 'amount';
        }

        if (self::scheduledDay($rule) !== $found['day']) {
            $flags[] = 'day';
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $rule
     */
    private static function hasStopped(array $summary, array $rule, string $today): bool
    {
        if ($summary['last'] === null) {
            return true;
        }

        // Measured against the rule's own frequency where the history has no cadence to
        // offer: a yearly rule that last paid eight months ago has not stopped, and a monthly
        // one that last paid a quarter ago has.
        $cadence = $summary['cadence'] ?? $rule['frequency'];
        $after = self::STOPPED_AFTER[$cadence] ?? self::STOPPED_AFTER['monthly'];

        return Carbon::parse($summary['last'])->diffInDays(Carbon::parse($today), true) > $after;
    }

    /**
     * The day a rule actually fires on, which is the day of its start date: a rule steps
     * from that day every month, so the start date is the schedule and the last recorded
     * occurrence says nothing about it.
     *
     * @param  array<string, mixed>  $rule
     */
    private static function scheduledDay(array $rule): int
    {
        return (int) Carbon::parse($rule['start_date'])->day;
    }

    /**
     * @param  array<string, mixed>  $found
     * @param  array<string, mixed>|null  $rule
     * @param  list<string>  $flags
     * @return array<string, mixed>
     */
    private static function verdict(array $found, ?array $rule, array $flags): array
    {
        $stopped = in_array('stopped', $flags, true);

        return [
            ...$found,
            'rule_id' => $rule['id'] ?? null,
            'rule_amount' => $rule['amount'] ?? null,
            'rule_day' => $rule === null ? null : self::scheduledDay($rule),
            'flags' => $flags,
            'verdict' => match (true) {
                $rule === null => 'new',
                $stopped => 'stopped',
                // Recent payments that are not on a cadence: nothing to change, and nothing
                // said, which is not the same thing as having stopped.
                $found['cadence'] === null => 'unclear',
                $flags === [] => 'matches',
                default => 'differs',
            },
        ];
    }

    /**
     * Case-insensitive, so the name is one thing however the payment was typed.
     *
     * @param  array<string, mixed>  $found
     */
    private static function ignored(array $found): bool
    {
        return $found['type'] === TransactionType::Deposit->value
            && in_array(
                strtoupper(trim((string) $found['description'])),
                array_map(strtoupper(...), self::IGNORED_DEPOSITS),
                true
            );
    }

    /** Compared as decimals, not as strings, so 25 and 25.0000 are one figure. */
    private static function sameAmount(string $a, string $b): bool
    {
        return BigDecimal::of($a)->toScale(4, RoundingMode::HalfUp)
            ->isEqualTo(BigDecimal::of($b)->toScale(4, RoundingMode::HalfUp));
    }

    /**
     * @param  array{0: int, 1: int}  $band
     */
    private static function within(int|float $gap, array $band): bool
    {
        return $gap >= $band[0] && $gap <= $band[1];
    }
}
