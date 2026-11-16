<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $user = $request->user();

        // 1. Navigation shortcuts
        $navItems = [
            [
                'title' => 'Dashboard',
                'description' => 'Today\'s sales, orders and stats',
                'url' => route('dashboard'),
                'icon' => 'LayoutDashboard',
                'permission' => null,
            ],
            [
                'title' => 'Point of Sale',
                'description' => 'Create new sale and scan barcode',
                'url' => route('pos'),
                'icon' => 'ScanBarcode',
                'permission' => Permission::Pos,
            ],
            [
                'title' => 'Order History',
                'description' => 'View past receipts and transactions',
                'url' => route('orders.index'),
                'icon' => 'Receipt',
                'permission' => Permission::Orders,
            ],
            [
                'title' => 'In Debt',
                'description' => 'Credit sales, tabs and balance settlements',
                'url' => route('debts.index'),
                'icon' => 'HandCoins',
                'permission' => Permission::Debts,
            ],
            [
                'title' => 'Products',
                'description' => 'Manage inventory catalogue and pricing',
                'url' => route('products.index'),
                'icon' => 'Boxes',
                'permission' => Permission::Products,
            ],
            [
                'title' => 'Categories',
                'description' => 'Product categories and organization',
                'url' => route('categories.index'),
                'icon' => 'Shapes',
                'permission' => Permission::Categories,
            ],
            [
                'title' => 'Inventory',
                'description' => 'Stock movements and alerts',
                'url' => route('inventory.index'),
                'icon' => 'PackageSearch',
                'permission' => Permission::Inventory,
            ],
            [
                'title' => 'Customers',
                'description' => 'Customer directory and loyalty',
                'url' => route('customers.index'),
                'icon' => 'Users',
                'permission' => Permission::Customers,
            ],
            [
                'title' => 'Vendors',
                'description' => 'Suppliers and supplier products',
                'url' => route('vendors.index'),
                'icon' => 'Building2',
                'permission' => Permission::Vendors,
            ],
            [
                'title' => 'Reports',
                'description' => 'Sales, margin and trend reports',
                'url' => route('reports.index'),
                'icon' => 'ChartNoAxesColumn',
                'permission' => Permission::Reports,
            ],
            [
                'title' => 'Analyze',
                'description' => 'Business performance, peak trading hours, margins and sales analytics',
                'url' => route('analyze.index'),
                'icon' => 'TrendingUp',
                'permission' => Permission::Reports,
            ],
            [
                'title' => 'Staff',
                'description' => 'Team accounts and access permissions',
                'url' => route('users.index'),
                'icon' => 'UserCheck',
                'permission' => Permission::Users,
            ],
            [
                'title' => 'Activity Log',
                'description' => 'Audit log of actions and changes',
                'url' => route('activity.index'),
                'icon' => 'History',
                'permission' => Permission::Activity,
            ],
            [
                'title' => 'Stores & Registers',
                'description' => 'Physical stores and POS cash registers',
                'url' => route('stores.index'),
                'icon' => 'Store',
                'permission' => Permission::Stores,
            ],
        ];

        $navigation = collect($navItems)
            ->filter(fn ($item) => $item['permission'] === null || $user->hasPermission($item['permission']))
            ->when($q !== '', function ($items) use ($q) {
                return $items->filter(function ($item) use ($q) {
                    return str_contains(strtolower($item['title']), strtolower($q))
                        || str_contains(strtolower($item['description']), strtolower($q));
                });
            })
            ->values()
            ->take(4);

        if ($q === '') {
            return response()->json([
                'navigation' => $navigation,
                'products' => [],
                'orders' => [],
                'customers' => [],
            ]);
        }

        // 2. Products
        $products = [];
        if ($user->hasPermission(Permission::Products)) {
            $products = Product::query()
                ->active()
                ->where(function ($query) use ($q) {
                    $query->where('name', 'like', "%{$q}%")
                        ->orWhere('sku', 'like', "%{$q}%")
                        ->orWhere('barcode', 'like', "%{$q}%");
                })
                ->limit(5)
                ->get(['id', 'uuid', 'name', 'sku', 'barcode', 'sell_price', 'unit', 'image'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'uuid' => $p->uuid,
                    'title' => $p->name,
                    'subtitle' => 'SKU: ' . ($p->sku ?: '-') . ($p->barcode ? ' • Barcode: ' . $p->barcode : ''),
                    'price' => $p->sell_price,
                    'unit' => $p->unit,
                    'image' => $p->image,
                    'url' => route('products.show', $p),
                ]);
        }

        // 3. Orders
        $orders = [];
        if ($user->hasPermission(Permission::Orders)) {
            $orders = Order::query()
                ->with('customer:id,name')
                ->where(function ($query) use ($q) {
                    $query->where('order_no', 'like', "%{$q}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%"));
                })
                ->latest()
                ->limit(5)
                ->get(['id', 'uuid', 'order_no', 'total', 'customer_id', 'status', 'created_at'])
                ->map(fn (Order $o) => [
                    'id' => $o->id,
                    'uuid' => $o->uuid,
                    'title' => 'Order #' . $o->order_no,
                    'subtitle' => ($o->customer ? $o->customer->name . ' • ' : '') . $o->created_at->format('M d, Y h:i A'),
                    'total' => $o->total,
                    'status' => $o->status->value ?? (string) $o->status,
                    'url' => route('orders.show', $o),
                ]);
        }

        // 4. Customers
        $customers = [];
        if ($user->hasPermission(Permission::Customers)) {
            $customers = Customer::query()
                ->where(function ($query) use ($q) {
                    $query->where('name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                })
                ->limit(5)
                ->get(['id', 'uuid', 'name', 'phone', 'email'])
                ->map(fn (Customer $c) => [
                    'id' => $c->id,
                    'uuid' => $c->uuid,
                    'title' => $c->name,
                    'subtitle' => $c->phone ?: ($c->email ?: 'Customer'),
                    'url' => route('customers.index', ['filter' => ['search' => $c->name]]),
                ]);
        }

        return response()->json([
            'navigation' => $navigation,
            'products' => $products,
            'orders' => $orders,
            'customers' => $customers,
        ]);
    }
}
