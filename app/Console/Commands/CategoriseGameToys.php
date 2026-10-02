<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Files the cash rows for OPCG, GCG and UA, trading card games, under GAME & TOY. The card
 * charges for the same things were categorised as they were entered; the cash ones were not,
 * so category spending leaves out whatever was paid in cash.
 *
 * A row matches on the whole word, so a description that merely contains the letters is not
 * swept in -- UA is inside "MEITUAN" and "ANNUAL" -- and only a row with no category is written. One already filed elsewhere is
 * reported and left alone, since the person who put it there may have meant it.
 *
 * Without --apply nothing is written, and a second run finds nothing left to do.
 */
class CategoriseGameToys extends Command
{
    private const CATEGORY = 'GAME & TOY';

    private const PATTERN = '/\b(OPCG|GCG|UA)\b/i';

    protected $signature = 'transactions:categorise-game-toys
        {--apply : Write the category; without it the run only reports}';

    protected $description = 'File cash transactions for OPCG, GCG and UA under the GAME & TOY category';

    public function handle(): int
    {
        $category = Category::query()->whereRaw('LOWER(name) = ?', [strtolower(self::CATEGORY)])->first();

        if ($category === null) {
            $this->error('There is no '.self::CATEGORY.' category. Create it first; this does not.');

            return self::FAILURE;
        }

        $matches = $this->matching();
        $todo = $matches->filter(fn (Transaction $row) => $row->category_id === null);
        $elsewhere = $matches->filter(fn (Transaction $row) => $row->category_id !== null && $row->category_id !== $category->id);

        $this->table(
            ['Matched', 'Already '.self::CATEGORY, 'Filed elsewhere', 'To categorise'],
            [[$matches->count(), $matches->count() - $todo->count() - $elsewhere->count(), $elsewhere->count(), $todo->count()]]
        );

        if ($elsewhere->isNotEmpty()) {
            $this->warn('Left alone, already in another category:');
            $this->table(
                ['ID', 'Date', 'Account', 'Description', 'Amount', 'Category'],
                $elsewhere->map(fn (Transaction $row) => [$row->id, $row->date, $row->account->name, $row->description, $row->amount, $row->category->name])->all()
            );
        }

        if (! $this->option('apply')) {
            $this->comment("{$todo->count()} rows to categorise. Nothing written. Re-run with --apply to write them.");

            return self::SUCCESS;
        }

        // One UPDATE, so the rows change together or not at all, and the ids are the ones reported.
        $written = Transaction::query()
            ->whereIn('id', $todo->modelKeys())
            ->whereNull('category_id')
            ->update(['category_id' => $category->id]);

        $this->info("Categorised {$written} rows as ".self::CATEGORY.'.');

        return self::SUCCESS;
    }

    /**
     * LIKE narrows the rows in the database; the word boundary is checked here, where MySQL
     * has no portable spelling for it.
     *
     * @return Collection<int, Transaction>
     */
    private function matching(): Collection
    {
        return Transaction::query()
            ->with(['account', 'category'])
            ->whereHas('account', fn ($q) => $q->where('type', AccountType::Cash->value))
            ->where(fn ($q) => $q->where('description', 'like', '%OPCG%')
                ->orWhere('description', 'like', '%GCG%')
                ->orWhere('description', 'like', '%UA%'))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Transaction $row) => preg_match(self::PATTERN, $row->description) === 1)
            ->values();
    }
}
