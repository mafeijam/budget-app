<?php

namespace Tests\Unit;

use App\Support\RecurringPatterns;
use PHPUnit\Framework\TestCase;

/**
 * What a run of transactions has to look like before a rule can be written from it, and
 * what a rule has to look like before the history is said to agree with it.
 *
 * Rows in, findings out: every case here is a list of dates and figures rather than a
 * database, because the question each one asks is about arithmetic and nothing else.
 */
class RecurringPatternsTest extends TestCase
{
    private const TODAY = '2026-09-30';

    public function test_a_monthly_subscription_is_found(): void
    {
        $finding = $this->find($this->history('KKBOX', '53.0000', [
            '2026-07-12', '2026-08-12', '2026-09-12',
        ]));

        $this->assertSame('new', $finding['verdict']);
        $this->assertSame('monthly', $finding['cadence']);
        $this->assertSame(3, $finding['occurrences']);
        $this->assertSame(12, $finding['day']);
        $this->assertSame('2026-10-12', $finding['start_date']);
    }

    public function test_a_deposit_is_found_at_its_latest_figure(): void
    {
        $finding = $this->find($this->history('SALARY', '40829.7000', [
            '2026-07-01', '2026-08-01', '2026-09-01',
        ], ['type' => 'deposit']));

        $this->assertSame('new', $finding['verdict']);
        $this->assertSame('40829.7000', $finding['amount']);
    }

    public function test_a_deposit_whose_figure_moves_is_still_a_pattern(): void
    {
        // A raise and a thirteenth month are figures, not gaps: the cadence is when it
        // arrives, and the figure it arrives with is whatever is current.
        $finding = $this->find($this->history('SALARY', '40000.0000', [
            '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01',
        ], ['type' => 'deposit']));

        $this->assertSame('monthly', $finding['cadence']);
        $this->assertSame('40000.0000', $finding['amount']);
    }

    public function test_a_thirteenth_month_does_not_break_a_deposit(): void
    {
        $rows = array_merge(
            $this->history('SALARY', '40000.0000', ['2025-11-01', '2025-12-01', '2026-01-01', '2026-02-01'], ['type' => 'deposit']),
            $this->history('SALARY', '83000.0000', ['2026-01-01'], ['type' => 'deposit'])
        );

        $this->assertSame('monthly', $this->find($rows)['cadence']);
    }

    public function test_one_odd_charge_in_the_middle_does_not_hide_a_subscription(): void
    {
        // PTCG is 78.78 every month on the 15th, with a 179.78 on the 30th in among them. A
        // run that stops at the first difference leaves one payment, and a subscription of
        // twenty-two reads as nothing recurring at all.
        $rows = array_merge(
            $this->history('PTCG', '78.7800', ['2026-06-15', '2026-07-15', '2026-08-15', '2026-09-15']),
            $this->history('PTCG', '179.7800', ['2026-08-30'])
        );

        $finding = $this->find($rows);

        $this->assertSame(4, $finding['occurrences']);
        $this->assertSame('78.7800', $finding['amount']);
        $this->assertSame('2026-10-15', $finding['start_date']);
    }

    public function test_two_yearly_payments_are_a_pattern(): void
    {
        // A yearly rule needs three payments to be found, and a two-year window holds only
        // two -- so asking for three would mean a yearly subscription could never be found.
        $finding = $this->find($this->history('MCAFEE', '545.4000', [
            '2024-12-12', '2025-12-12',
        ]));

        $this->assertSame('yearly', $finding['cadence']);
        $this->assertSame(12, $finding['day']);
        $this->assertSame('2026-12-12', $finding['start_date']);
    }

    public function test_a_figure_that_moved_resolves_to_the_newer_one(): void
    {
        $rows = array_merge(
            $this->history('NETFLIX', '88.8800', ['2026-02-11', '2026-03-11', '2026-04-11']),
            $this->history('NETFLIX', '98.9800', ['2026-05-10', '2026-06-10', '2026-07-10', '2026-08-10'])
        );

        $finding = $this->find($rows);

        $this->assertSame('98.9800', $finding['amount']);
        $this->assertSame('2026-10-10', $finding['start_date']);
    }

    public function test_one_late_payment_does_not_carry_the_day(): void
    {
        // Nineteen on the 20th and a few on the 21st: the rule belongs on the 20th, and a
        // start date on the 21st would contradict the day the report names.
        $dates = ['2026-05-20', '2026-06-20', '2026-07-20', '2026-08-21', '2026-09-20'];

        $finding = $this->find($this->history('CSL', '156.0000', $dates));

        $this->assertSame(20, $finding['day']);
        $this->assertSame('2026-10-20', $finding['start_date']);
    }

    public function test_a_purchase_on_another_day_does_not_widen_the_schedule(): void
    {
        // A game bought on its release day, then a monthly payment on the 7th. Measuring
        // from the earliest to the latest day makes a schedule that does not move look like
        // one that moves five days.
        $rows = array_merge(
            $this->history('GAME', '39.0000', ['2026-04-10']),
            $this->history('GAME', '39.0000', ['2026-05-08', '2026-06-07', '2026-07-07', '2026-08-06', '2026-09-05'])
        );

        $this->assertSame(7, $this->find($rows)['day']);
    }

    public function test_a_gap_a_month_long_is_not_a_pattern(): void
    {
        $this->assertNotFound($this->history('JOCKEY', '100.0000', [
            '2026-01-27', '2026-04-10', '2026-05-13', '2026-06-08', '2026-07-03',
        ]));
    }

    public function test_several_payments_inside_a_fortnight_are_not_a_pattern(): void
    {
        $this->assertNotFound($this->history('TOPUP', '3000.0000', [
            '2026-03-13', '2026-03-15', '2026-03-17', '2026-03-21',
        ]));
    }

    public function test_a_day_that_wanders_is_not_a_pattern(): void
    {
        $this->assertNotFound($this->history('JOCKEY', '100.0000', [
            '2026-01-03', '2026-02-14', '2026-03-27', '2026-04-19', '2026-05-31', '2026-06-12',
        ]));
    }

    public function test_a_day_that_wanders_four_days_is_still_a_pattern(): void
    {
        // The 25th to the 29th is a monthly payment, not a schedule that moves.
        $this->assertSame(27, $this->find($this->history('PCCW', '204.0000', [
            '2026-01-25', '2026-02-26', '2026-03-27', '2026-04-28', '2026-05-29', '2026-06-27',
        ]))['day']);
    }

    public function test_two_days_equally_often_are_settled_on_the_first(): void
    {
        // A schedule on the 27th or the 28th is a schedule, and the earlier of the two is the
        // one a rule can be written from. Both are still reported.
        $finding = $this->find($this->history('PCCW', '204.0000', [
            '2026-01-27', '2026-02-28', '2026-03-27', '2026-04-28', '2026-05-27', '2026-06-28',
        ]));

        $this->assertSame(27, $finding['day']);
        $this->assertSame([27, 28], $finding['tied_days']);
        $this->assertSame('2026-10-27', $finding['start_date']);
    }

    public function test_two_yearly_payments_on_different_days_take_the_newest(): void
    {
        // Nothing is more common than anything else, so the only evidence about when it
        // happens now is the last one.
        $finding = $this->find($this->history('GODADDY', '231.2900', ['2025-06-22', '2026-06-24']));

        $this->assertSame(24, $finding['day']);
        $this->assertSame('2027-06-24', $finding['start_date']);
    }

    public function test_the_named_credit_is_never_offered(): void
    {
        $this->assertNotFound($this->history('CREDIT INTEREST', '0.5600', [
            '2026-07-28', '2026-08-28', '2026-09-28',
        ], ['type' => 'deposit']));
    }

    public function test_another_credit_of_the_same_size_is_offered(): void
    {
        // The name is the exclusion and not a floor, so a credit it does not name is judged
        // on its cadence like anything else.
        $this->assertNotNull($this->find($this->history('DIVIDEND', '0.5600', [
            '2026-07-28', '2026-08-28', '2026-09-28',
        ], ['type' => 'deposit'])));
    }

    public function test_a_named_credit_does_not_hide_an_existing_rule(): void
    {
        $finding = $this->find(
            $this->history('CREDIT INTEREST', '0.5600', ['2026-07-28', '2026-08-28', '2026-09-28'], ['type' => 'deposit']),
            [$this->rule(['description' => 'CREDIT INTEREST', 'amount' => '0.5600', 'start_date' => '2026-09-28'])]
        );

        $this->assertSame('matches', $finding['verdict'], 'A rule somebody wrote is still their rule.');
    }

    public function test_a_rule_the_history_agrees_with_is_left_alone(): void
    {
        $finding = $this->find(
            $this->history('KKBOX', '53.0000', ['2026-07-12', '2026-08-12', '2026-09-12']),
            [$this->rule(['description' => 'KKBOX', 'amount' => '53.0000', 'start_date' => '2026-07-12'])]
        );

        $this->assertSame('matches', $finding['verdict']);
        $this->assertSame([], $finding['flags']);
    }

    public function test_a_rule_on_the_wrong_day_and_figure_says_so(): void
    {
        $finding = $this->find(
            $this->history('NETFLIX', '98.9800', ['2026-07-10', '2026-08-10', '2026-09-10']),
            [$this->rule(['description' => 'NETFLIX', 'amount' => '88.8800', 'start_date' => '2026-01-01'])]
        );

        $this->assertSame('differs', $finding['verdict']);
        $this->assertSame(['amount', 'day'], $finding['flags']);
    }

    public function test_a_rule_whose_payments_stopped_is_reported_stopped(): void
    {
        $finding = $this->find([], [$this->rule(['start_date' => '2024-09-01'])], 'FIND ME');

        $this->assertSame('stopped', $finding['verdict']);
        $this->assertNull($finding['last']);
    }

    public function test_one_missed_month_does_not_hide_a_subscription(): void
    {
        // HMVOD missed a January and has paid six times since, every gap but one of them a
        // month. Reading that single gap as the end of the pattern left a rule firing on the
        // 1st beside a subscription that pays on the 13th, with nothing to act on.
        $dates = ['2026-01-13', '2026-03-13', '2026-04-13', '2026-05-13', '2026-06-13', '2026-07-13', '2026-08-13'];

        $finding = $this->find(
            $this->history('HMVOD', '48.4800', $dates),
            [$this->rule(['description' => 'HMVOD', 'amount' => '48.4800', 'start_date' => '2026-01-01'])]
        );

        $this->assertSame('differs', $finding['verdict']);
        $this->assertSame('monthly', $finding['cadence']);
        $this->assertSame(['day'], $finding['flags']);
    }

    public function test_a_pattern_with_too_many_gaps_is_still_not_a_pattern(): void
    {
        // Two thirds of the gaps have to be in one band, so a schedule that is mostly one
        // thing is a pattern and one that is a bit of everything is not.
        $this->assertNotFound($this->history('SOMETHING', '100.0000', [
            '2026-01-13', '2026-02-20', '2026-04-02', '2026-05-19', '2026-06-30', '2026-08-11',
        ]));
    }

    public function test_a_yearly_rule_last_paid_eight_months_ago_has_not_stopped(): void
    {
        $finding = $this->find(
            $this->history('MCAFEE', '545.4000', ['2024-12-12']),
            [$this->rule(['description' => 'MCAFEE', 'frequency' => 'yearly', 'start_date' => '2024-12-12'])]
        );

        $this->assertSame('unclear', $finding['verdict']);
    }

    public function test_two_rules_on_one_description_are_both_reported(): void
    {
        $rows = $this->history('INSTALMENT', '2800.0000', ['2026-07-01', '2026-08-01', '2026-09-01']);

        $findings = RecurringPatterns::find($rows, [
            $this->rule(['id' => 1, 'description' => 'INSTALMENT', 'amount' => '2800.0000', 'start_date' => '2026-01-01']),
            $this->rule(['id' => 2, 'description' => 'INSTALMENT', 'amount' => '336.0000', 'start_date' => '2026-01-01']),
        ], self::TODAY);

        $this->assertCount(2, $findings, 'A key of account and description drops one of them.');
        $this->assertSame([1, 2], array_column($findings, 'rule_id'));
    }

    public function test_a_new_rule_starts_on_its_next_payment_and_not_the_first_ever(): void
    {
        // The recorder writes a pending row for every occurrence since start_date, so a rule
        // started on the first payment in the history would write one for every month since.
        $finding = $this->find($this->history('KKBOX', '53.0000', [
            '2025-10-12', '2025-11-12', '2025-12-12', '2026-01-12', '2026-02-12',
            '2026-03-12', '2026-04-12', '2026-05-12', '2026-06-12', '2026-07-12', '2026-08-12', '2026-09-12',
        ]));

        $this->assertSame('2025-10-12', $finding['first']);
        $this->assertSame('2026-10-12', $finding['start_date']);
    }

    public function test_a_pattern_with_no_rule_is_offered_and_a_covered_one_is_not(): void
    {
        $rows = $this->history('KKBOX', '53.0000', ['2026-07-12', '2026-08-12', '2026-09-12']);
        $other = $this->history('POKEMON', '39.0000', ['2026-07-07', '2026-08-07', '2026-09-07']);

        $findings = RecurringPatterns::find(array_merge($rows, $other), [
            $this->rule(['description' => 'KKBOX', 'amount' => '53.0000', 'start_date' => '2026-07-12']),
        ], self::TODAY);

        $this->assertSame(['POKEMON'], array_column(array_filter(
            $findings,
            fn (array $f) => $f['verdict'] === 'new'
        ), 'description'), 'A pattern a rule already covers is not offered as a new one.');
    }

    /**
     * The finding for one description, named after the rows it is being looked for in.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function find(array $rows, array $rules = [], ?string $description = null): array
    {
        $found = $this->finding($rows, $rules, $description);

        $this->assertNotNull($found, 'Nothing in '.count($rows).' rows looks recurring.');

        return $found;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertNotFound(array $rows): void
    {
        $this->assertNull(
            $this->finding($rows),
            'A pattern was offered that the history does not support.'
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, mixed>|null
     */
    private function finding(array $rows, array $rules = [], ?string $description = null): ?array
    {
        $description ??= $rows[0]['description'] ?? null;

        foreach (RecurringPatterns::find($rows, $rules, self::TODAY) as $finding) {
            if ($finding['description'] === $description) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $dates
     * @param  array<string, mixed>  $over
     * @return list<array<string, mixed>>
     */
    private function history(string $description, string $amount, array $dates, array $over = []): array
    {
        return array_map(fn (string $date) => array_merge([
            'account_id' => 1,
            'account' => 'Broker',
            'type' => 'charge',
            'description' => $description,
            'ccy' => 'HKD',
            'date' => $date,
            'amount' => $amount,
            'category_id' => null,
        ], $over), $dates);
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function rule(array $over = []): array
    {
        return array_merge([
            'id' => 1,
            'account_id' => 1,
            'account' => 'Broker',
            'description' => 'FIND ME',
            'amount' => '10.0000',
            'frequency' => 'monthly',
            'start_date' => '2026-01-01',
        ], $over);
    }
}
