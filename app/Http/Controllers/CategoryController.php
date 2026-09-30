<?php

namespace App\Http\Controllers;

use App\DTO\CategoryData;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Support\CashFlow;
use App\Support\Fx;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\LaravelData\PaginatedDataCollection;

class CategoryController extends Controller
{
    public function index(Request $r)
    {
        $formEmpty = CategoryData::empty();

        // Every category on one page, ranked on screen by what was spent under it. Still a
        // paginator, because saving and deleting reload through it.
        $accounts = Category::query()
            ->orderBy('name')
            ->paginate(max(1, Category::query()->count()));

        // Before Data::collect(), which replaces the paginator's models with DTOs.
        $refusals = $this->deleteRefusals($accounts->getCollection());

        $data = CategoryData::collect($accounts, PaginatedDataCollection::class);

        $params = ['sort' => 'name', 'dir' => 'asc'];

        // The Cash flow report by charge date: a category says what was bought, and by due
        // date this month's purchases would be missing until next month's statement.
        $combined = CashFlow::combined(today(), onDueDate: false);
        $spending = $this->spending($combined['report']['months'] ?? []);
        $usage = $this->usage();
        $base = Fx::BASE->value;
        $unconverted = $combined['unconverted'];
        $months = array_column($combined['report']['months'] ?? [], 'month');

        // The days the figures cover, so a category's link lists exactly what it summed.
        $window = [
            'from' => $combined['report']['months'][0]['from'] ?? null,
            'to' => ($combined['report']['months'] ?? []) === [] ? null : end($combined['report']['months'])['to'],
        ];

        $meta = [
            'form' => 'category-form',
            'path' => '/categories',
        ];

        return inertia('category', compact(
            'formEmpty', 'data', 'params', 'meta', 'refusals',
            'spending', 'usage', 'base', 'unconverted', 'months', 'window',
        ));
    }

    public function store(CategoryData $data)
    {
        $category = Category::create($data->toArray());

        return back()->with('message', "Category [$category->name] created");
    }

    public function update(Category $category, CategoryData $data)
    {
        $category->update($data->toArray());

        return back()->with('message', "Category [$category->name] updated");
    }

    /**
     * Per category, its spending in each month of the report and over the whole of it; 0 is
     * the spending filed under no category, which is worth seeing as much as any other.
     *
     * @param  list<array<string, mixed>>  $months
     * @return array{total: string, categories: array<int, array{months: list<string>, total: string, average: string}>}
     */
    private function spending(array $months): array
    {
        $zero = BigDecimal::zero();
        $byCategory = [];

        foreach ($months as $i => $month) {
            foreach ($month['categories'] as $category) {
                $byCategory[$category['id'] ?? 0][$i] = $category['amount'];
            }
        }

        $total = $zero;
        $categories = [];

        foreach ($byCategory as $id => $amounts) {
            $series = [];
            $sum = $zero;

            foreach (array_keys($months) as $i) {
                $series[] = $amounts[$i] ?? '0.0000';
                $sum = $sum->plus($amounts[$i] ?? $zero);
            }

            $categories[$id] = [
                'months' => $series,
                'total' => (string) $sum->toScale(4),
                // Over the whole window, empty months included, as Forecast averages the net.
                'average' => (string) $sum->dividedBy(max(1, count($months)), 4, RoundingMode::HalfUp),
            ];
            $total = $total->plus($sum);
        }

        return ['total' => (string) $total->toScale(4), 'categories' => $categories];
    }

    /**
     * Per category, how many transactions and recurring rules are filed under it, and the
     * day it was last used: the only way to tell an income category from an abandoned one.
     *
     * @return array<int, array{transactions: int, last_date: string|null, recurring: int}>
     */
    private function usage(): array
    {
        $rows = Transaction::query()
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) AS n, MAX(date) AS last_date')
            ->groupBy('category_id')
            ->get()
            ->keyBy('category_id');

        $recurring = RecurringTransaction::query()
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) AS n')
            ->groupBy('category_id')
            ->pluck('n', 'category_id');

        $usage = [];

        foreach (Category::query()->pluck('id') as $id) {
            $usage[$id] = [
                'transactions' => (int) ($rows[$id]->n ?? 0),
                'last_date' => $rows[$id]->last_date ?? null,
                'recurring' => (int) ($recurring[$id] ?? 0),
            ];
        }

        return $usage;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<int, string>
     */
    private function deleteRefusals(Collection $categories): array
    {
        $counts = Transaction::query()
            ->whereIn('category_id', $categories->pluck('id'))
            ->selectRaw('category_id, COUNT(*) AS n')
            ->groupBy('category_id')
            ->pluck('n', 'category_id');

        $recurring = RecurringTransaction::query()
            ->whereIn('category_id', $categories->pluck('id'))
            ->selectRaw('category_id, COUNT(*) AS n')
            ->groupBy('category_id')
            ->pluck('n', 'category_id');

        $refusals = [];

        foreach ($categories as $category) {
            $count = (int) ($counts[$category->id] ?? 0);
            $repeats = (int) ($recurring[$category->id] ?? 0);

            if ($count === 0 && $repeats > 0) {
                $refusals[$category->id] = sprintf(
                    'Category [%s] is used by %s recurring transaction%s and cannot be deleted. '
                        .'Move %s to another category first.',
                    $category->name,
                    $repeats,
                    $repeats === 1 ? '' : 's',
                    $repeats === 1 ? 'it' : 'them'
                );
            }

            if ($count > 0) {
                $refusals[$category->id] = sprintf(
                    'Category [%s] has %s transaction%s and cannot be deleted. Rename it, or move '
                        .'%s to another category first.',
                    $category->name,
                    $count,
                    $count === 1 ? '' : 's',
                    $count === 1 ? 'it' : 'them'
                );
            }
        }

        return $refusals;
    }

    public function destroy(Category $category)
    {
        // Checked before the restrictOnDelete foreign key, which would throw a 500.
        $refusal = $this->deleteRefusals(collect([$category]))[$category->id] ?? null;

        if ($refusal !== null) {
            return back()->with('message', $refusal);
        }

        $category->delete();

        return back()->with('message', "Category [$category->name] deleted");
    }
}
