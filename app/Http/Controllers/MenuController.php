<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public, unauthenticated product catalogue.
 *
 * Deliberately read-only: no cart, no stock figures, no staff data. The only
 * thing leaving the server here is what a customer would see on a printed
 * menu board, so it is safe to expose without a session.
 */
class MenuController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search'));
        $categoryId = $request->input('category');

        $products = Product::query()
            ->active()
            ->with('category:id,name,parent_id')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'image' => $p->image,
                'unit' => $p->unit,
                'category_id' => $p->category_id,
                'category_name' => $p->category?->name,

                /*
                 * Prices are stored tax-exclusive, but a customer-facing menu
                 * must show what they actually pay — quoting the net figure
                 * would understate every price at the till.
                 */
                'price' => round(
                    (float) $p->sell_price * (1 + $p->effectiveTaxRate() / 100),
                    2
                ),
            ])
            ->values();

        // Only categories that actually have something to show.
        $usedCategoryIds = $products->pluck('category_id')->unique();

        return Inertia::render('Menu/Index', [
            'products' => $products,
            'categories' => Category::query()
                ->whereIn('id', $usedCategoryIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'category' => $categoryId ? (int) $categoryId : null,
            ],
            'shop' => [
                'name' => Setting::get('receipt_header', config('app.name')),
                'footer' => Setting::get('receipt_footer'),
                'currency' => Setting::get('currency_symbol', '$'),
            ],
        ]);
    }
}
