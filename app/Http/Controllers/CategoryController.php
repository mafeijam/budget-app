<?php

namespace App\Http\Controllers;

use App\DTO\CategoryData;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Spatie\LaravelData\PaginatedDataCollection;

class CategoryController extends Controller
{
    public function index(Request $r)
    {
        $formEmpty = CategoryData::empty();

        $accounts = Category::query()
            ->orderBy($r->input('sort', 'created_at'), $r->input('dir', 'desc'))
            ->paginate($r->input('per_page', 5));

        $data = CategoryData::collect($accounts, PaginatedDataCollection::class);

        $params = $r->query() + ['sort' => 'created_at', 'dir' => 'desc'];

        $meta = [
            'form' => 'category-form',
            'path' => '/categories',
        ];

        return inertia('category', compact('formEmpty', 'data', 'params', 'meta'));
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

    public function destroy(Category $category)
    {
        // Checked here rather than left to the foreign key, which refuses this by
        // throwing: category_id is restrictOnDelete, so deleting a category anything is
        // filed under came back as a 500 with the row still there and nothing said
        // about why. As in AccountController::destroy, the constraint belongs to the
        // schema and the answer belongs in a message.
        $transactions = Transaction::where('category_id', $category->id)->count();

        if ($transactions > 0) {
            return back()->with('message', sprintf(
                'Category [%s] has %s transaction%s and cannot be deleted',
                $category->name,
                $transactions,
                $transactions === 1 ? '' : 's'
            ));
        }

        $category->delete();

        return back()->with('message', "Category [$category->name] deleted");
    }
}
