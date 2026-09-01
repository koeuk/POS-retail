<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ConsumptionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebtController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosDataController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
 * This is staff software, not a marketing site — there is no landing page.
 * Guests go straight to login; signed-in staff go to their home screen.
 */
Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect()->route('dashboard');
})->name('home');

/*
 * Public customer menu — the one route in this app with no auth. Read-only
 * catalogue: names, photos and prices, nothing about stock or staff.
 */
Route::get('menu', [MenuController::class, 'index'])->name('menu');

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
| The bare 'role' middleware takes no arguments here — it only enforces
| is_active, so a user deactivated mid-shift is locked out on their next
| request rather than lingering until the session expires.
*/
Route::middleware(['auth', 'verified', 'role'])->group(function () {

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
    | POS data endpoints — JSON, not Inertia. See PosDataController.
    | Products and order sync land here in Phase 4.
    */
    Route::get('pos', [PosController::class, 'index'])->name('pos')->middleware('permission:pos');

    Route::prefix('pos/data')->name('pos.data.')->middleware('permission:pos')->group(function () {
        Route::get('heartbeat', [PosDataController::class, 'heartbeat'])->name('heartbeat');
        Route::get('products', [PosDataController::class, 'products'])->name('products');
        Route::get('customers', [PosDataController::class, 'customers'])->name('customers');
        Route::post('customers', [PosDataController::class, 'storeCustomer'])->name('customers.store');
        Route::post('orders/sync', [PosDataController::class, 'sync'])->name('orders.sync');
        Route::get('orders/{clientUuid}/status', [PosDataController::class, 'status'])
            ->name('orders.status');
    });

    /*
    |----------------------------------------------------------------------
    | Admin area
    |----------------------------------------------------------------------
    | Gated per feature: a role sets the defaults and each user can be
    | granted or denied a feature on the Staff screen. Finer-grained rules
    | (who may edit vs only read) live in the policies, not here.
    */
    Route::middleware('permission:debts')->group(function () {
        Route::get('debts', [DebtController::class, 'index'])->name('debts.index');
        Route::post('debts', [DebtController::class, 'store'])->name('debts.store');
        Route::get('debts/product-lookup', [DebtController::class, 'productLookup'])->name('debts.products');
        Route::post('debts/{order}/settle', [DebtController::class, 'settle'])->name('debts.settle');
    });

    Route::get('consumption', [ConsumptionController::class, 'index'])
        ->name('consumption.index')->middleware('permission:consumption');

    Route::middleware('permission:orders')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    });

    Route::middleware('permission:reports')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
    });

    Route::get('admin/ping', function () {
        return response()->json(['ok' => true, 'area' => 'admin']);
    })->name('admin.ping')->middleware('role:admin,manager');

    /*
    | Inventory. Movements, not raw edits — see InventoryController.
    */
    Route::middleware('permission:inventory')->group(function () {
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory/lookup', [InventoryController::class, 'lookup'])->name('inventory.lookup');
        Route::post('inventory/movements', [InventoryController::class, 'store'])->name('inventory.store');
        Route::put('inventory/threshold', [InventoryController::class, 'updateThreshold'])->name('inventory.threshold');
    });

    Route::resource('products', ProductController::class)->middleware('permission:products');

    Route::resource('categories', CategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])->middleware('permission:categories');

    Route::resource('customers', CustomerController::class)
        ->only(['index', 'store', 'update', 'destroy'])->middleware('permission:customers');

    Route::resource('users', UserController::class)
        ->only(['index', 'store', 'update', 'destroy'])->middleware('permission:users');

    Route::middleware('permission:stores')->group(function () {
        Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
        Route::post('stores', [StoreController::class, 'store'])->name('stores.store');
        Route::put('stores/{store}', [StoreController::class, 'update'])->name('stores.update');
        Route::delete('stores/{store}', [StoreController::class, 'destroy'])->name('stores.destroy');
        Route::post('stores/{store}/registers', [StoreController::class, 'storeRegister'])
            ->name('stores.registers.store');
        Route::put('stores/{store}/registers/{register}', [StoreController::class, 'updateRegister'])
            ->name('stores.registers.update');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
