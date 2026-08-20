<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\PosDataController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

    Route::get('dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    /*
    | POS data endpoints — JSON, not Inertia. See PosDataController.
    | Products and order sync land here in Phase 4.
    */
    Route::prefix('pos/data')->name('pos.data.')->group(function () {
        Route::get('heartbeat', [PosDataController::class, 'heartbeat'])->name('heartbeat');
    });

    /*
    |----------------------------------------------------------------------
    | Admin area
    |----------------------------------------------------------------------
    | Gated to admin + manager. Finer-grained rules (who may edit vs only
    | read) live in the policies, not here.
    */
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('admin/ping', function () {
            return response()->json(['ok' => true, 'area' => 'admin']);
        })->name('admin.ping');

        Route::resource('products', ProductController::class)->except('show');

        Route::resource('categories', CategoryController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::resource('customers', CustomerController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::resource('users', UserController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
        Route::post('stores', [StoreController::class, 'store'])->name('stores.store');
        Route::put('stores/{store}', [StoreController::class, 'update'])->name('stores.update');
        Route::post('stores/{store}/registers', [StoreController::class, 'storeRegister'])
            ->name('stores.registers.store');
        Route::put('stores/{store}/registers/{register}', [StoreController::class, 'updateRegister'])
            ->name('stores.registers.update');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
