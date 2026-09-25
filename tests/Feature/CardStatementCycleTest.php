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
 * The rule needs two anchors, not one. A due day on its own cannot place a
 * charge in a period -- many different statement days produce the same due day
 * -- so the statement day is what decides the cycle boundaries and the due day
 * only follows from it.
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
    private function cycle(int $statementDay = 25, int $dueDay = 15): CardStatementCycle
    {
        return new CardStatementCycle($statementDay, $dueDay);
    }

    /**
     * Card closing on the 25th, due on the 15th of the following month.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function closingOnThe25thDueOnThe15thProvider(): array
    {
        return [
            // The statement running 26 Aug to 25 Sep is due 15 Oct.
            'first day of the period' => ['2026-08-26', '2026-10-15'],
            'charge spanning the month boundary' => ['2026-09-20', '2026-10-15'],
            'charge the day before closing' => ['2026-09-24', '2026-10-15'],
            'charge on the closing day is in that statement' => ['2026-09-25', '2026-10-15'],
            // The next statement runs 26 Sep to 25 Oct and is due 15 Nov.
            'charge the day after closing' => ['2026-09-26', '2026-11-15'],
            'charge early in the next period' => ['2026-10-05', '2026-11-15'],
            'last day of the next period' => ['2026-10-25', '2026-11-15'],
        ];
    }

    #[DataProvider('closingOnThe25thDueOnThe15thProvider')]
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
            'closes late in the month, due early in the next' => [25, 15],
            'closes early in the month, due later in the same month' => [5, 25],
            'closes and falls due on the same day' => [10, 10],
            'closes on the first, falls due on the last' => [1, 31],
            'closes on the last, falls due on the first' => [31, 1],
        ];
    }

    #[DataProvider('cardTermsProvider')]
    public function test_a_charge_never_falls_due_before_it_is_made(
        int $statementDay,
        int $dueDay
    ): void {
        // The invariant that catches an inverted comparison. A statement can
        // close before the charge lands, but it can never come due before the
        // charge itself, because that would demand payment for a purchase not
        // yet made. Checked over a whole year so month-length edge cases come
        // along for free.
        $cycle = $this->cycle($statementDay, $dueDay);
        $charge = Carbon::parse('2026-01-01');

        for ($day = 0; $day < 365; $day++) {
            $this->assertTrue(
                $cycle->dueDateFor($charge)->gt($charge),
                "A charge on {$charge->toDateString()} came due before it was made, on a card "
                    ."closing on the {$statementDay}th and due on the {$dueDay}th."
            );

            $charge = $charge->copy()->addDay();
        }
    }

    #[DataProvider('cardTermsProvider')]
    public function test_every_period_is_a_contiguous_run_of_days(
        int $statementDay,
        int $dueDay
    ): void {
        // Two charges either side of a closing day must land in different
        // periods, and the boundary must be the closing day itself. A rule that
        // grouped by calendar month instead would agree on most dates and
        // disagree exactly here.
        $cycle = $this->cycle($statementDay, $dueDay);
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

    public function test_a_due_day_before_the_statement_day_falls_in_the_same_month(): void
    {
        // Some cards close early in the month and are due later in that same
        // month. The due date is the first day-U strictly after the closing
        // date, so it must not be pushed a whole extra month.
        $cycle = $this->cycle(statementDay: 5, dueDay: 25);

        $this->assertSame('2026-09-25', $cycle->dueDateFor(Carbon::parse('2026-09-03'))->toDateString());
        $this->assertSame('2026-09-25', $cycle->dueDateFor(Carbon::parse('2026-09-05'))->toDateString());
        $this->assertSame('2026-10-25', $cycle->dueDateFor(Carbon::parse('2026-09-20'))->toDateString());
    }

    public function test_a_closing_day_past_the_end_of_a_short_month_clamps(): void
    {
        // A card closing on the 31st has no 31st in February. Clamping to the
        // 28th keeps the March charge in the March statement; letting the date
        // overflow would slide it back and pull a whole period forward.
        $cycle = $this->cycle(statementDay: 31, dueDay: 15);

        $this->assertSame(
            '2026-04-15',
            $cycle->dueDateFor(Carbon::parse('2026-03-01'))->toDateString()
        );
    }

    public function test_a_due_day_past_the_end_of_a_short_month_clamps(): void
    {
        // Closing on the 30th and due on the 30th puts the due date in the
        // following month, which here is February. The 30th does not exist
        // there, so the statement comes due on the 28th rather than sliding
        // forward into March -- paying later than agreed is the wrong way to
        // be wrong.
        $cycle = $this->cycle(statementDay: 30, dueDay: 30);

        $this->assertSame(
            '2026-02-28',
            $cycle->dueDateFor(Carbon::parse('2026-01-30'))->toDateString()
        );
    }

    public function test_it_handles_a_leap_day(): void
    {
        $due = $this->cycle(statementDay: 29, dueDay: 15)->dueDateFor(Carbon::parse('2024-02-29'));

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
        $this->assertSame(15, $cycle->dueDay());
    }

    public function test_it_is_built_from_account_meta(): void
    {
        $cycle = CardStatementCycle::fromMeta(['due' => '15', 'statement_day' => 25]);

        $this->assertInstanceOf(CardStatementCycle::class, $cycle);
        $this->assertSame(25, $cycle->statementDay());
        $this->assertSame(15, $cycle->dueDay());
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
        $this->assertSame(15, $cycle->dueDay());
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

    public function test_an_impossible_due_day_is_also_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CardStatementCycle(15, 0);
    }
}
