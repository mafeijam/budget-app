<?php

namespace Tests\Feature;

use App\Support\CardStatementCycle;
use ArrayObject;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the mapping from a charge date onto the statement cycle that contains
 * it, and from that cycle onto the day it falls due.
 *
 * The two numbers mean different things. statement_day is a day of the month and
 * draws the cycle boundaries; the term is a number of days counted forward from
 * the closing day. So the term is an interval and not a calendar day, which is
 * why a card closing on the 25th with a 20-day term is due in the middle of the
 * following month rather than on the 20th of anything.
 *
 * The load-bearing constraint is that a statement can never come due before the
 * charge on it was made. The test for that runs a full year of dates across
 * several card configurations, because an inverted comparison is exactly the
 * kind of bug that every hand-picked example agrees with: a "most recent
 * closing on or before the charge" rule looks right for any charge made
 * *before* the closing day, and is wrong for every charge made after it.
 */
class CardStatementCycleTest extends TestCase
{
    private function cycle(int $statementDay = 25, int $termDays = 15): CardStatementCycle
    {
        return new CardStatementCycle($statementDay, $termDays);
    }

    /**
     * Card closing on the 25th, payable 15 days later.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function closingOnThe25thPayableIn15DaysProvider(): array
    {
        return [
            // The statement running 26 Aug to 25 Sep closes on the 25th and is
            // payable 15 days later.
            'first day of the period' => ['2026-08-26', '2026-10-10'],
            'charge spanning the month boundary' => ['2026-09-20', '2026-10-10'],
            'charge the day before closing' => ['2026-09-24', '2026-10-10'],
            'charge on the closing day is in that statement' => ['2026-09-25', '2026-10-10'],
            // The next statement runs 26 Sep to 25 Oct and is payable 9 Nov.
            'charge the day after closing' => ['2026-09-26', '2026-11-09'],
            'charge early in the next period' => ['2026-10-05', '2026-11-09'],
            'last day of the next period' => ['2026-10-25', '2026-11-09'],
        ];
    }

    #[DataProvider('closingOnThe25thPayableIn15DaysProvider')]
    public function test_charge_maps_onto_the_due_date_of_its_own_period(
        string $chargeDate,
        string $expectedDueDate
    ): void {
        $due = $this->cycle()->dueDateFor(Carbon::parse($chargeDate));

        $this->assertSame($expectedDueDate, $due->toDateString());
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function cardTermsProvider(): array
    {
        return [
            'closes late in the month, payable into the next' => [25, 15],
            'closes early in the month, payable past the month end' => [5, 25],
            'shortest payable term' => [10, 1],
            'longest payable term' => [1, 31],
            'closes on the last day of a short month' => [31, 1],
        ];
    }

    #[DataProvider('cardTermsProvider')]
    public function test_a_charge_never_falls_due_before_it_is_made(
        int $statementDay,
        int $termDays
    ): void {
        // The invariant that catches an inverted comparison. A statement can
        // close before the charge lands, but it can never come due before the
        // charge itself, because that would demand payment for a purchase not
        // yet made. Checked over a whole year so month-length edge cases come
        // along for free.
        $cycle = $this->cycle($statementDay, $termDays);
        $charge = Carbon::parse('2026-01-01');

        for ($day = 0; $day < 365; $day++) {
            $this->assertTrue(
                $cycle->dueDateFor($charge)->gt($charge),
                "A charge on {$charge->toDateString()} came due before it was made, on a card "
                    ."closing on the {$statementDay}th and payable {$termDays} days later."
            );

            $charge = $charge->copy()->addDay();
        }
    }

    #[DataProvider('cardTermsProvider')]
    public function test_every_period_is_a_contiguous_run_of_days(
        int $statementDay,
        int $termDays
    ): void {
        // Two charges either side of a closing day must land in different
        // periods, and the boundary must be the closing day itself. A rule that
        // grouped by calendar month instead would agree on most dates and
        // disagree exactly here.
        $cycle = $this->cycle($statementDay, $termDays);
        $charge = Carbon::parse('2026-01-01');

        $previous = null;
        $periods = [];

        for ($day = 0; $day < 365; $day++) {
            $due = $cycle->dueDateFor($charge)->toDateString();

            if ($previous !== null && $due !== $previous) {
                $periods[] = $previous;
            }

            $previous = $due;
            $charge = $charge->copy()->addDay();
        }

        // A year has to produce several periods, and each must be a single run:
        // a given due date must never be reached again after being left.
        $this->assertGreaterThan(
            10,
            count($periods),
            'A year of charges produced too few statement periods to be plausible.'
        );
        $this->assertSame(
            count($periods),
            count(array_unique($periods)),
            'A due date was revisited after being left, so periods are not contiguous runs.'
        );
    }

    public function test_the_term_is_measured_from_the_closing_day_not_the_charge(): void
    {
        // The single most important thing the term is not. A charge on 1 Jan on
        // a card closing on the 25th with a 15-day term is due 9 Feb, 39 days
        // later -- not 16 Jan, which is what counting from the charge would give.
        $due = $this->cycle()->dueDateFor(Carbon::parse('2026-01-01'));

        $this->assertSame('2026-02-09', $due->toDateString());
    }

    public function test_the_term_is_the_interval_from_the_closing_day(): void
    {
        // Only the term changes, so the gap between the two due dates is exactly
        // the gap between the two terms. If either the closing day or the
        // interval were computed differently, these would not be 5 days apart.
        $charge = Carbon::parse('2026-01-01');

        $fifteen = $this->cycle(25, 15)->dueDateFor($charge);
        $twenty = $this->cycle(25, 20)->dueDateFor($charge);

        $this->assertSame('2026-02-09', $fifteen->toDateString());
        $this->assertSame('2026-02-14', $twenty->toDateString());
        $this->assertSame(5, (int) $fifteen->diffInDays($twenty));
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: int}>
     */
    public static function monthLengthProvider(): array
    {
        // statement_day 15, term 20 -- each closing in a month of a different
        // length, so the interval has to survive 28, 30 and 31 day months.
        return [
            'February in a common year' => [15, '2026-02-15', 28],
            'April' => [15, '2026-04-15', 30],
            'January' => [15, '2026-01-15', 31],
        ];
    }

    #[DataProvider('monthLengthProvider')]
    public function test_the_term_is_exactly_as_long_in_every_month(
        int $statementDay,
        string $closingDate,
        int $closingMonthLength
    ): void {
        // A term counted in days must not stretch or shrink with the month it
        // lands in. Under a day-of-month due date these three would have been
        // the 20th each time, and February's would have been pulled back to the
        // 28th -- paying later than agreed, the wrong way to be wrong.
        $closing = Carbon::parse($closingDate);
        $cycle = $this->cycle($statementDay, termDays: 20);

        $due = $cycle->dueDateFor($closing);

        $this->assertSame(
            20,
            (int) $closing->diffInDays($due),
            "A {$closingMonthLength}-day month did not leave the 20-day term at 20 days."
        );
    }

    public function test_a_closing_day_past_the_end_of_a_short_month_clamps(): void
    {
        // A card closing on the 31st has no 31st in February, so the February
        // statement closes on the 28th. Carbon's day() setter overflows rather
        // than clamping, so an unclamped 31st slides to 3 March -- which would
        // close the February statement three days late and pull the whole cycle
        // forward with it.
        $cycle = $this->cycle(statementDay: 31, termDays: 15);

        $this->assertSame(
            '2026-03-15',
            $cycle->dueDateFor(Carbon::parse('2026-02-10'))->toDateString(),
            'February closed late, so the statement that contains a 10 Feb charge came due in April.'
        );
    }

    public function test_it_handles_a_leap_day(): void
    {
        $due = $this->cycle(statementDay: 29, termDays: 15)->dueDateFor(Carbon::parse('2024-02-29'));

        $this->assertSame('2024-03-15', $due->toDateString());
    }

    public function test_it_does_not_mutate_the_date_it_is_given(): void
    {
        // The DTO hands over its own $date property. Mutating it in place would
        // silently change the value that gets persisted as the charge date.
        $charge = Carbon::parse('2026-09-20');
        $before = $charge->toDateString();

        $this->cycle()->dueDateFor($charge);

        $this->assertSame($before, $charge->toDateString());
    }

    public function test_the_days_are_exposed_for_display(): void
    {
        $cycle = $this->cycle();

        $this->assertSame(25, $cycle->statementDay());
        $this->assertSame(15, $cycle->termDays());
    }

    public function test_it_is_built_from_account_meta(): void
    {
        $cycle = CardStatementCycle::fromMeta(['due' => '15', 'statement_day' => 25]);

        $this->assertInstanceOf(CardStatementCycle::class, $cycle);
        $this->assertSame(25, $cycle->statementDay());
        $this->assertSame(15, $cycle->termDays());
    }

    public function test_it_accepts_the_array_object_the_meta_model_casts_to(): void
    {
        // Meta casts its JSON column to an ArrayObject, so this -- not a plain
        // array -- is what a caller reading $account->meta->meta actually holds.
        $cycle = CardStatementCycle::fromMeta(
            new ArrayObject(['due' => '15', 'statement_day' => 25])
        );

        $this->assertNotNull($cycle);
        $this->assertSame(25, $cycle->statementDay());
        $this->assertSame(15, $cycle->termDays());
    }

    public function test_it_is_null_when_the_account_has_no_card_terms(): void
    {
        // Cash and securities accounts have no statement, so there is no cycle
        // to derive. Returning null rather than throwing keeps the caller free
        // of account-type branching.
        $this->assertNull(CardStatementCycle::fromMeta(null));
        $this->assertNull(CardStatementCycle::fromMeta([]));
        $this->assertNull(CardStatementCycle::fromMeta(['due' => '15']));
        $this->assertNull(CardStatementCycle::fromMeta(['statement_day' => 25]));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function outOfRangeDayProvider(): array
    {
        return [
            'zero statement day' => [0],
            'statement day past the month' => [32],
            'negative statement day' => [-1],
        ];
    }

    #[DataProvider('outOfRangeDayProvider')]
    public function test_an_impossible_day_is_rejected_rather_than_clamped_silently(int $day): void
    {
        // Clamping is for "the 31st does not exist this month", which is a
        // property of the calendar. A day of 0 or 32 is a data-entry error and
        // must not quietly become the 1st or the 28th.
        $this->expectException(\InvalidArgumentException::class);

        new CardStatementCycle($day, 15);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function impossibleTermProvider(): array
    {
        return [
            'zero days, which would be payable as it closes' => [0],
            'more days than a statement period can plausibly run' => [32],
            'negative term' => [-1],
        ];
    }

    #[DataProvider('impossibleTermProvider')]
    public function test_an_impossible_term_is_rejected(int $days): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CardStatementCycle(15, $days);
    }
}
