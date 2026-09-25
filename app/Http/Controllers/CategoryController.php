<?php

namespace App\Http\Controllers;

use App\DTO\CategoryData;
use App\Models\Category;
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
        $category->delete();

        return back()->with('message', "Category [$category->name] deleted");
    }
}
