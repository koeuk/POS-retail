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
            // Base products only. A case and a can are one item on a menu with
            // two prices, not two entries a customer has to reconcile.
            ->base()
            ->with('category:id,name')
            ->with(['packs' => fn ($q) => $q->active()->orderBy('units_per_pack')])
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
                 * Every way this item can be bought, cheapest first. The base
                 * product is always the first entry — it is the single unit.
                 */
                'packs' => $p->packs
                    ->map(fn (Product $pack) => [
                        'id' => $pack->id,
                        'name' => $pack->name,
                        'units' => $pack->units_per_pack,
                        'price' => (float) $pack->sell_price,
                    ])
                    ->values(),

                'price' => (float) $p->sell_price,
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
