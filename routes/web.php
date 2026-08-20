<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
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
    Route::get('pos', [PosController::class, 'index'])->name('pos');

    Route::prefix('pos/data')->name('pos.data.')->group(function () {
        Route::get('heartbeat', [PosDataController::class, 'heartbeat'])->name('heartbeat');
        Route::get('products', [PosDataController::class, 'products'])->name('products');
        Route::post('orders/sync', [PosDataController::class, 'sync'])->name('orders.sync');
        Route::get('orders/{clientUuid}/status', [PosDataController::class, 'status'])
            ->name('orders.status');
    });

    /*
    |----------------------------------------------------------------------
    | Admin area
    |----------------------------------------------------------------------
    | Gated to admin + manager. Finer-grained rules (who may edit vs only
    | read) live in the policies, not here.
    */
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

        Route::get('admin/ping', function () {
            return response()->json(['ok' => true, 'area' => 'admin']);
        })->name('admin.ping');

        /*
        | Inventory. Movements, not raw edits — see InventoryController.
        */
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory/lookup', [InventoryController::class, 'lookup'])->name('inventory.lookup');
        Route::post('inventory/movements', [InventoryController::class, 'store'])->name('inventory.store');
        Route::put('inventory/threshold', [InventoryController::class, 'updateThreshold'])->name('inventory.threshold');

        Route::resource('products', ProductController::class);

        Route::resource('categories', CategoryController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::resource('customers', CustomerController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::resource('users', UserController::class)
            ->only(['index', 'store', 'update', 'destroy']);

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
