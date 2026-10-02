<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Support\Loans;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tags the rows of each loan below, so net worth carries what is still owed on it. See
 * App\Support\Loans for what the tags mean.
 *
 * The rows are found by their description, type and amount rather than by id, so the same
 * run tags the same loans on another copy of the ledger. Each loan must come out at exactly
 * zero after its last instalment, and every instalment must be there once; a loan that does
 * not is reported and left untagged, because a loan tagged short is a debt that never ends.
 *
 * Without --apply nothing is written, and a second run finds every row tagged already.
 */
class TagLoans extends Command
{
    protected $signature = 'loans:tag
        {--apply : Write the tags; without it the run only reports}
        {--untag : Remove every loan tag instead}';

    protected $description = 'Tag the drawdown and repayments of each known loan, so net worth subtracts what is owed';

    /**
     * Each loan: the drawdown, or null where it predates the records; what a repayment looks
     * like; and each instalment's principal and interest, and how many there are. `amounts` are
     * the rows one instalment is paid in, which add up to its principal and interest. A
     * pattern's group, where it has one, is the instalment's number, so a missing one is found
     * by its gap.
     */
    private const LOANS = [
        [
            'name' => 'HSBC TAX LOAN',
            'drawdown' => ['type' => 'deposit', 'pattern' => '/^HSBC TAX LOAN$/i'],
            // A flat 11,574 a month, of which 324 is interest: 24 x 11,250 is the 270,000.
            'repayment' => ['type' => 'withdraw', 'pattern' => '/^HSBC TAX LOAN$/i', 'amounts' => ['11574']],
            'principal' => '11250',
            'interest' => '324',
            'term' => 24,
        ],
        [
            'name' => 'HSBC LOAN',
            'drawdown' => ['type' => 'deposit', 'pattern' => '/^HSBC LOAN$/i'],
            // Two charges an instalment under one description: the principal and the interest.
            'repayment' => ['type' => 'charge', 'pattern' => '/^INSTALMENT (\d+) OF 60$/i', 'amounts' => ['2800', '336']],
            'principal' => '2800',
            'interest' => '336',
            'term' => 60,
        ],
        [
            'name' => 'MASTER INSTALMENT LOAN',
            // Borrowed in mid-2016; the card's records start at instalment 6.
            'drawdown' => null,
            'repayment' => ['type' => 'charge', 'pattern' => '/^INSTALMENT (\d+) OF 36$/i', 'amounts' => ['6250', '360']],
            'principal' => '6250',
            'interest' => '360',
            'term' => 36,
        ],
    ];

    public function handle(): int
    {
        if ($this->option('untag')) {
            return $this->untag();
        }

        $plans = [];
        $failed = false;

        foreach (self::LOANS as $loan) {
            $plan = $this->plan($loan);

            if ($plan === null) {
                $this->line("{$loan['name']}: none of its rows are in this ledger, skipped.");

                continue;
            }

            if (is_string($plan)) {
                $this->error("{$loan['name']}: {$plan} Left untagged.");
                $failed = true;

                continue;
            }

            $plans[] = $plan;
        }

        $this->table(
            ['Loan', 'Borrowed', 'Interest', 'Drawdown', 'Repayment rows', 'Before the records', 'Instalment', 'To tag'],
            array_map(fn (array $plan) => [
                $plan['name'],
                $plan['borrowed'],
                $plan['interest'],
                $plan['drawdown'] === null ? '-' : "#{$plan['drawdown']->id} {$plan['drawdown']->date}",
                count($plan['repayments']).' ('.$plan['repayments'][0]->date.' to '.end($plan['repayments'])->date.')',
                $plan['before'] === 0 ? '-' : "{$plan['before']} instalments",
                $plan['instalment'],
                count($plan['tags']),
            ], $plans)
        );

        $tags = array_merge(...array_column($plans, 'tags'));

        if (! $this->option('apply')) {
            $this->comment(count($tags).' rows to tag. Nothing written. Re-run with --apply to tag them.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        DB::transaction(function () use ($tags) {
            foreach ($tags as [$row, $tag]) {
                $this->write($row, $tag);
            }
        });

        $this->info('Tagged '.count($tags).' rows.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The rows a loan tags and what each gets, why it cannot be tagged, or null when none of
     * its rows are here: a ledger that never had the loan is not a broken one.
     *
     * @param  array<string, mixed>  $loan
     * @return array<string, mixed>|string|null
     */
    private function plan(array $loan): array|string|null
    {
        $principal = BigDecimal::of($loan['principal']);
        $instalment = $principal->plus($loan['interest']);
        $borrowed = $principal->multipliedBy($loan['term']);
        $interest = BigDecimal::of($loan['interest'])->multipliedBy($loan['term']);
        $pattern = $loan['repayment']['pattern'];

        if (! BigDecimal::sum(...$loan['repayment']['amounts'])->isEqualTo($instalment)) {
            return 'its rows do not add up to an instalment of '.$instalment.'.';
        }

        // One run per row an instalment is paid in, each checked for its own gaps.
        $runs = array_map(
            fn (string $amount) => $this->matching($loan['name'], $loan['repayment']['type'], $pattern, $amount)->values(),
            $loan['repayment']['amounts'],
        );
        $repayments = collect($runs)->flatten(1);
        $drawdown = null;

        if ($loan['drawdown'] !== null) {
            $found = $this->matching($loan['name'], $loan['drawdown']['type'], $loan['drawdown']['pattern']);

            if ($found->isEmpty() && $repayments->isEmpty()) {
                return null;
            }

            if ($found->count() !== 1) {
                return "{$found->count()} drawdowns match, where there must be one.";
            }

            $drawdown = $found->first();

            if (! $borrowed->isEqualTo($drawdown->amount)) {
                return "the drawdown is {$drawdown->amount}, not the {$borrowed} its {$loan['term']} instalments of {$principal} repay.";
            }
        }

        if ($repayments->isEmpty()) {
            return $drawdown === null ? null : 'no repayments match.';
        }

        $before = null;

        foreach ($runs as $i => $run) {
            // Numbered where the description numbers them, otherwise in date order from the first.
            $numbers = $run->map(fn (Transaction $row) => preg_match($pattern, trim((string) $row->description), $m) && isset($m[1])
                ? (int) $m[1]
                : null);
            $numbers = $numbers->isEmpty() || $numbers->contains(null) ? $run->keys()->map(fn (int $k) => $k + 1) : $numbers;
            $first = $numbers->min() ?? $loan['term'] + 1;
            $before ??= $first - 1;
            $amount = $loan['repayment']['amounts'][$i];

            if ($numbers->sort()->values()->all() !== range($before + 1, $loan['term'])) {
                return "the repayments of {$amount} are not instalments ".($before + 1)." to {$loan['term']}, once each: found "
                    .$numbers->count().($numbers->isEmpty() ? '' : ' numbered '.$numbers->sort()->implode(', ')).'.';
            }
        }

        if ($drawdown !== null && $before > 0) {
            return 'the drawdown is recorded but the first repayment is instalment '.($before + 1).'.';
        }

        $tags = [];

        if ($drawdown !== null) {
            $tags[] = [$drawdown, ['loan' => $loan['name'], 'loan_interest' => (string) $interest]];
        }

        foreach ($runs as $i => $run) {
            foreach ($run as $row) {
                $tags[$row->id] = [$row, ['loan' => $loan['name'], 'loan_repaid' => $loan['repayment']['amounts'][$i]]];
            }
        }

        // The first recorded principal carries what was owed when the records start.
        if ($drawdown === null) {
            $first = $runs[0]->first();
            $tags[$first->id][1] += [
                'loan_borrowed' => (string) $borrowed,
                'loan_interest' => (string) $interest,
                'loan_repaid_before' => (string) $instalment->multipliedBy($before),
            ];
        }

        return [
            'name' => $loan['name'],
            'borrowed' => (string) $borrowed,
            'interest' => (string) $interest,
            'drawdown' => $drawdown,
            'repayments' => $repayments->sortBy('date')->values()->all(),
            'before' => $before,
            'instalment' => (string) $instalment,
            'tags' => array_values(array_filter($tags, fn (array $pair) => ! $this->has($pair[0], $pair[1]))),
        ];
    }

    /**
     * The rows of a type whose description matches, and amount where one is given, oldest
     * first. A row tagged for another loan is never one of them.
     *
     * @return Collection<int, Transaction>
     */
    private function matching(string $name, string $type, string $pattern, ?string $amount = null): Collection
    {
        return Transaction::query()
            ->with('meta')
            ->where('type', $type)
            ->when($amount !== null, fn ($query) => $query->where('amount', $amount))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Transaction $row) => preg_match($pattern, trim((string) $row->description)) === 1
                && in_array($row->meta?->meta['loan'] ?? null, [null, $name], true))
            ->values();
    }

    /** @param  array<string, string>  $tag */
    private function has(Transaction $row, array $tag): bool
    {
        $bag = $row->meta?->meta?->getArrayCopy() ?? [];

        return array_intersect_key($bag, array_flip([...Loans::KEYS, ...Loans::RETIRED])) == $tag;
    }

    /** @param  array<string, string>  $tag */
    private function write(Transaction $row, array $tag): void
    {
        $bag = array_diff_key($row->meta?->meta?->getArrayCopy() ?? [], array_flip([...Loans::KEYS, ...Loans::RETIRED]));
        $row->meta()->updateOrCreate(['id' => $row->meta?->id], ['meta' => [...$bag, ...$tag]]);
    }

    private function untag(): int
    {
        $rows = Loans::tagged()->with('meta')->get();

        if (! $this->option('apply')) {
            $this->comment("{$rows->count()} rows tagged. Nothing written. Re-run with --untag --apply to remove the tags.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $this->write($row, []);
            }
        });

        $this->info("Removed the tags from {$rows->count()} rows.");

        return self::SUCCESS;
    }
}
