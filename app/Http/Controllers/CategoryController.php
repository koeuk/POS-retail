<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Category::class);

        return Inertia::render('Categories/Index', [
            'categories' => Category::query()
                ->withCount('products')
                ->when(
                    $request->input('search'),
                    fn ($q, $search) => $q->where('name', 'like', "%{$search}%")
                )
                ->orderBy('name')
                ->get(),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        try {
            $this->authorize('create', Category::class);

            Category::create($request->validated());

            return back()->with('success', 'Category created.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The category could not be saved. Nothing was changed — try again.');
        }
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        try {
            $this->authorize('update', $category);

            $category->update($request->validated());

            return back()->with('success', 'Category updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The category could not be saved. Nothing was changed — try again.');
        }
    }

    public function destroy(Category $category): RedirectResponse
    {
        try {
            $this->authorize('delete', $category);

            if ($category->products()->exists()) {
                return back()->withErrors([
                    'category' => 'This category still has products. Move them first.',
                ]);
            }

            $category->delete();

            return back()->with('success', 'Category deleted.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The category could not be deleted. Nothing was changed — try again.');
        }
    }
}
