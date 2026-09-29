<?php

namespace App\Console\Commands;

use App\Support\RecurringApply;
use App\Support\RecurringScan;
use Illuminate\Console\Command;

/**
 * What the history says the recurring rules should be. The same scan the Find from history
 * button on the Recurring page runs, and the same apply, so the two cannot disagree about
 * what a year of payments looks like.
 */
class DetectRecurring extends Command
{
    protected $signature = 'recurring:detect
        {--months=24 : How far back to look; a yearly rule needs three, so 24 cannot find one}
        {--apply : Create, correct and delete the rules the history supports}';

    protected $description = 'Find recurring payments in the transaction history, and optionally bring the rules into line with it';

    /** The findings a run can act on, most worth looking at first. */
    private const ORDER = ['differs' => 0, 'new' => 1, 'stopped' => 2, 'unclear' => 3, 'matches' => 4];

    public function handle(): int
    {
        $months = (int) $this->option('months');

        if ($months < 1) {
            $this->error('--months is a number of months, and that is not one.');

            return self::FAILURE;
        }

        $findings = RecurringScan::findings($months);
        $actionable = array_filter($findings, fn (array $f) => $f['verdict'] !== 'matches');

        usort($actionable, fn (array $a, array $b) => [self::ORDER[$a['verdict']], $a['description']]
            <=> [self::ORDER[$b['verdict']], $b['description']]);

        $this->table(
            ['Verdict', 'Account', 'Description', 'Type', 'History', 'Rule', 'Found', 'Schedules', 'On'],
            array_map(fn (array $f) => [
                $f['verdict'],
                $f['account'],
                $f['description'],
                (string) $f['type'],
                $f['amount'] === null ? '' : $f['amount'].' '.$f['ccy'],
                $f['rule_amount'] === null ? '' : $f['rule_amount'].' on day '.$f['rule_day'],
                $f['occurrences'] > 0 ? $f['occurrences'].'x '.($f['cadence'] ?? 'not monthly') : 'no payments',
                $f['day'] === null
                    ? ($f['tied_days'] === [] ? 'n/a' : 'ambiguous '.implode('/', $f['tied_days']))
                    : 'day '.$f['day'],
                $f['start_date'] ?? '-',
            ], $actionable)
        );

        $count = fn (string ...$verdicts) => count(array_filter(
            $findings,
            fn (array $f) => in_array($f['verdict'], $verdicts, true)
        ));

        $this->line(sprintf(
            '%d rules, %d patterns in %d months: %d to act on, %d not on a cadence, %d already right.',
            count(RecurringScan::rules()),
            count($findings),
            $months,
            $count('differs', 'new', 'stopped'),
            $count('unclear'),
            $count('matches')
        ));

        if (! $this->option('apply')) {
            $this->comment('Nothing written. Re-run with --apply to put the rules in line with this.');

            return self::SUCCESS;
        }

        $done = RecurringApply::apply($findings);

        $this->info(ucfirst(RecurringApply::summary($done)).'.');

        foreach ($done['skipped'] as $skipped) {
            $this->warn("Skipped {$skipped}");
        }

        return self::SUCCESS;
    }
}
