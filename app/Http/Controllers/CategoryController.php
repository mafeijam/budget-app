<?php

namespace App\Http\Controllers;

use App\DTO\CategoryData;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\LaravelData\PaginatedDataCollection;

class CategoryController extends Controller
{
    public function index(Request $r)
    {
        $formEmpty = CategoryData::empty();

        $accounts = Category::query()
            ->orderBy($r->input('sort', 'created_at'), $r->input('dir', 'desc'))
            ->paginate($r->input('per_page', 5));

        // Before Data::collect(), which replaces the paginator's models with DTOs.
        $refusals = $this->deleteRefusals($accounts->getCollection());

        $data = CategoryData::collect($accounts, PaginatedDataCollection::class);

        $params = $r->query() + ['sort' => 'created_at', 'dir' => 'desc'];

        $meta = [
            'form' => 'category-form',
            'path' => '/categories',
        ];

        return inertia('category', compact('formEmpty', 'data', 'params', 'meta', 'refusals'));
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
     * Why each category in a set cannot be deleted, keyed by id, for the ones that
     * cannot: one query for the page, and the same sentence destroy() refuses with, so
     * the disabled button's tooltip and the refusal cannot drift.
     *
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

        $refusals = [];

        foreach ($categories as $category) {
            $count = (int) ($counts[$category->id] ?? 0);

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
        // Checked here rather than left to the foreign key, which refuses this by
        // throwing: category_id is restrictOnDelete, so deleting a category anything is
        // filed under came back as a 500 with the row still there and nothing said
        // about why. As in AccountController::destroy, the constraint belongs to the
        // schema and the answer belongs in a message.
        $refusal = $this->deleteRefusals(collect([$category]))[$category->id] ?? null;

        if ($refusal !== null) {
            return back()->with('message', $refusal);
        }

        $category->delete();

        return back()->with('message', "Category [$category->name] deleted");
    }
}
