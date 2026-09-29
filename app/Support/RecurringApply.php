<?php

namespace App\Support;

use App\Models\RecurringTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Writes what a scan found: rules created from patterns, rules brought into line with the
 * figures and days the history shows, and rules deleted for payments that have stopped.
 *
 * The whole run is one database transaction, so a rule that cannot be written leaves the set
 * as it was rather than half-adopted -- a subscription list is read by the recorder on every
 * run, and half of one is worse than none of one.
 */
class RecurringApply
{
    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array{created: list<string>, updated: list<string>, deleted: list<string>, skipped: list<string>}
     */
    public static function apply(array $findings): array
    {
        $done = ['created' => [], 'updated' => [], 'deleted' => [], 'skipped' => []];

        DB::transaction(function () use ($findings, &$done) {
            foreach ($findings as $finding) {
                match ($finding['verdict']) {
                    'new' => self::create($finding, $done),
                    'differs' => self::correct($finding, $done),
                    'stopped' => self::delete($finding, $done),
                    default => $done['skipped'][] = self::label($finding),
                };
            }
        });

        return $done;
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array{created: list<string>, updated: list<string>, deleted: list<string>, skipped: list<string>}  $done
     */
    private static function create(array $finding, array &$done): void
    {
        // No start date means the day of the month is ambiguous, and a rule started on a
        // guessed day is wrong on every month the guess is wrong for.
        if ($finding['start_date'] === null) {
            $done['skipped'][] = self::label($finding).' (no single day to schedule it on)';

            return;
        }

        RecurringTransaction::create([
            'account_id' => $finding['account_id'],
            'category_id' => $finding['category_id'],
            'type' => $finding['type'],
            'description' => $finding['description'],
            'amount' => $finding['amount'],
            'ccy' => $finding['ccy'],
            'frequency' => $finding['cadence'],
            // The next occurrence, never the first in the history: the recorder writes a
            // pending row for every occurrence since this date, and those months are
            // already in the ledger as posted rows.
            'start_date' => $finding['start_date'],
        ]);

        $done['created'][] = self::label($finding);
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array{created: list<string>, updated: list<string>, deleted: list<string>, skipped: list<string>}  $done
     */
    private static function correct(array $finding, array &$done): void
    {
        $rule = RecurringTransaction::find($finding['rule_id']);

        if ($rule === null) {
            $done['skipped'][] = self::label($finding).' (rule no longer there)';

            return;
        }

        // A rule is brought to the figure the history shows and to the day it usually lands
        // on. Both are decided, so both are applied: a day the history is torn between is
        // settled on the first of the days it names, not left alone.
        $rule->amount = $finding['amount'];
        $rule->start_date = $finding['start_date'];
        $rule->save();

        $done['updated'][] = self::label($finding);
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array{created: list<string>, updated: list<string>, deleted: list<string>, skipped: list<string>}  $done
     */
    private static function delete(array $finding, array &$done): void
    {
        $rule = RecurringTransaction::find($finding['rule_id']);

        if ($rule === null) {
            return;
        }

        // What the rule already wrote stays: those rows are history, and each is deleted on
        // its own or not at all.
        $rule->delete();

        $done['deleted'][] = self::label($finding);
    }

    /** @param array<string, mixed> $finding */
    private static function label(array $finding): string
    {
        return sprintf('[%s] on %s', $finding['description'], $finding['account']);
    }

    /**
     * What the report needs about a run, for the one line that says what was written. Kept
     * here rather than in the command, so the button and the command say the same thing.
     *
     * @param  array{created: list<string>, updated: list<string>, deleted: list<string>, skipped: list<string>}  $done
     */
    public static function summary(array $done): string
    {
        $count = fn (string $key) => count($done[$key]);
        $parts = [];

        foreach (['created' => 'added', 'updated' => 'brought up to date', 'deleted' => 'removed'] as $key => $verb) {
            if ($count($key) > 0) {
                $parts[] = $count($key).' '.$verb;
            }
        }

        return $parts === [] ? 'nothing to change' : implode(', ', $parts);
    }
}
