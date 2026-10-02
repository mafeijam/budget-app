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
     * like; how much of each pays the loan down, and how many there are. A pattern's group,
     * where it has one, is the instalment's number, so a missing one is found by its gap.
     */
    private const LOANS = [
        [
            'name' => 'HSBC TAX LOAN',
            'drawdown' => ['type' => 'deposit', 'pattern' => '/^HSBC TAX LOAN$/i'],
            // A flat 11,574 a month, of which 324 is interest: 24 x 11,250 is the 270,000.
            'repayment' => ['type' => 'withdraw', 'pattern' => '/^HSBC TAX LOAN$/i', 'amount' => '11574'],
            'principal' => '11250',
            'term' => 24,
        ],
        [
            'name' => 'HSBC LOAN',
            'drawdown' => ['type' => 'deposit', 'pattern' => '/^HSBC LOAN$/i'],
            // Its interest is a second charge of 336 under the same description, left untagged.
            'repayment' => ['type' => 'charge', 'pattern' => '/^INSTALMENT (\d+) OF 60$/i', 'amount' => '2800'],
            'principal' => '2800',
            'term' => 60,
        ],
        [
            'name' => 'MASTER INSTALMENT LOAN',
            // Borrowed in mid-2016; the card's records start at instalment 6.
            'drawdown' => null,
            'repayment' => ['type' => 'charge', 'pattern' => '/^INSTALMENT (\d+) OF 36$/i', 'amount' => '6250'],
            'principal' => '6250',
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
            ['Loan', 'Borrowed', 'Drawdown', 'Repayments', 'Before the records', 'Principal each', 'To tag'],
            array_map(fn (array $plan) => [
                $plan['name'],
                $plan['borrowed'],
                $plan['drawdown'] === null ? '-' : "#{$plan['drawdown']->id} {$plan['drawdown']->date}",
                count($plan['repayments']).' ('.$plan['repayments'][0]->date.' to '.end($plan['repayments'])->date.')',
                $plan['before'] === 0 ? '-' : "{$plan['before']} instalments",
                $plan['principal'],
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
        $borrowed = $principal->multipliedBy($loan['term']);
        $drawdown = null;
        $repayments = $this->matching($loan['name'], $loan['repayment']['type'], $loan['repayment']['pattern'], $loan['repayment']['amount'])->values();

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

        // Numbered where the description numbers them, otherwise in date order from the first.
        $numbers = $repayments->map(fn (Transaction $row) => preg_match($loan['repayment']['pattern'], $row->description, $m) && isset($m[1])
            ? (int) $m[1]
            : null);
        $numbers = $numbers->contains(null) ? $repayments->keys()->map(fn (int $i) => $i + 1) : $numbers;
        $before = $numbers->min() - 1;

        if ($drawdown !== null && $before > 0) {
            return "the drawdown is recorded but the first repayment is instalment {$numbers->min()}.";
        }

        if ($numbers->sort()->values()->all() !== range($before + 1, $loan['term'])) {
            return "the repayments are not instalments {$numbers->min()} to {$loan['term']}, once each: found "
                .$numbers->count().' numbered '.$numbers->sort()->implode(', ').'.';
        }

        $tags = [];

        if ($drawdown !== null) {
            $tags[] = [$drawdown, ['loan' => $loan['name']]];
        }

        foreach ($repayments as $i => $row) {
            $tag = ['loan' => $loan['name'], 'loan_principal' => (string) $principal];

            // The first recorded one carries what was owed when the records start.
            if ($drawdown === null && $i === 0) {
                $tag['loan_borrowed'] = (string) $borrowed;
                $tag['loan_repaid_before'] = (string) $principal->multipliedBy($before);
            }

            $tags[] = [$row, $tag];
        }

        return [
            'name' => $loan['name'],
            'borrowed' => (string) $borrowed,
            'drawdown' => $drawdown,
            'repayments' => $repayments->all(),
            'before' => $before,
            'principal' => (string) $principal,
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

        return array_intersect_key($bag, array_flip(Loans::KEYS)) == $tag;
    }

    /** @param  array<string, string>  $tag */
    private function write(Transaction $row, array $tag): void
    {
        $bag = array_diff_key($row->meta?->meta?->getArrayCopy() ?? [], array_flip(Loans::KEYS));
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
