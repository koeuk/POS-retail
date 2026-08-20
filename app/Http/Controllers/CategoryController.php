<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\Category;
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
                ->with('parent:id,name')
                ->withCount(['products', 'children'])
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
        $this->authorize('create', Category::class);

        Category::create($request->validated());

        return back()->with('success', 'Category created.');
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $data = $request->validated();

        // Reparenting must not create a cycle — walking up from the proposed
        // parent must never arrive back at this category.
        if ($data['parent_id'] && $this->wouldCycle($category, (int) $data['parent_id'])) {
            return back()->withErrors([
                'parent_id' => 'That would nest the category inside one of its own descendants.',
            ]);
        }

        $category->update($data);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        if ($category->products()->exists()) {
            return back()->withErrors([
                'category' => 'This category still has products. Move them first.',
            ]);
        }

        // Orphaned children are promoted to roots rather than deleted.
        $category->children()->update(['parent_id' => null]);
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    private function wouldCycle(Category $category, int $proposedParentId): bool
    {
        $seen = [];
        $cursor = Category::find($proposedParentId);

        while ($cursor) {
            if ($cursor->id === $category->id) {
                return true;
            }

            // Guard against a pre-existing cycle in the data.
            if (in_array($cursor->id, $seen, true)) {
                return true;
            }

            $seen[] = $cursor->id;
            $cursor = $cursor->parent_id ? Category::find($cursor->parent_id) : null;
        }

        return false;
    }
}
