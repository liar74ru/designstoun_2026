<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RawMaterialBatchController;
use App\Http\Controllers\StoneReceptionController;
use App\Http\Controllers\WorkerController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\StoreController;

Route::get('/', function () {
    return view('home');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Ресурсные маршруты для товаров (все методы: index, create, store, show, edit, update, destroy)
Route::resource('products', ProductController::class)->except(['edit']);

// Дополнительные маршруты для товаров
Route::get('/products/sync/moysklad', [ProductController::class, 'syncFromMoySklad'])
    ->name('products.sync');
Route::get('/products/{id}/refresh', [ProductController::class, 'refresh'])
    ->name('products.refresh');
Route::post('/products/stocks/sync-all-by-stores', [ProductController::class, 'syncAllProductsStocks'])
    ->name('products.stocks.sync-all-by-stores');
Route::get('/products/groups/tree', [ProductController::class, 'groups'])
    ->name('products.groups');
Route::post('/products/stocks/sync-all', [ProductController::class, 'syncAllStocks'])
    ->name('products.stocks.sync-all');
Route::post('/products/{moyskladId}/stocks-sync', [ProductController::class, 'syncStocks'])
    ->name('products.stocks.sync');

// Ресурсные маршруты для заказов (все методы: index, create, store, show, edit, update, destroy)
Route::resource('orders', OrderController::class);

// Ресурсный маршрут для работников
Route::resource('workers', WorkerController::class)->except([
    'show'
]);

Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');
Route::post('/stores/sync', [StoreController::class, 'sync'])->name('stores.sync');

// Массовая синхронизация остатков по всем складам
Route::post('/stores/stocks/sync-all', [StoreController::class, 'syncAllStocks'])
    ->name('stores.stocks.sync-all');
// Синхронизация остатков для конкретного склада
Route::post('/stores/{store}/stocks-sync', [StoreController::class, 'syncStoreStocks'])
    ->name('stores.stocks.sync');

Route::resource('stone-receptions', StoneReceptionController::class);
Route::post('stone-receptions/{stoneReception}/copy', [StoneReceptionController::class, 'copy'])
    ->name('stone-receptions.copy');


Route::resource('raw-batches', RawMaterialBatchController::class)->except(['edit', 'update']);

// Дополнительные маршруты для передачи и возврата
Route::get('raw-batches/{batch}/transfer', [RawMaterialBatchController::class, 'transferForm'])
    ->name('raw-batches.transfer.form');
Route::post('raw-batches/{batch}/transfer', [RawMaterialBatchController::class, 'transfer'])
    ->name('raw-batches.transfer');

Route::get('raw-batches/{batch}/return', [RawMaterialBatchController::class, 'returnForm'])
    ->name('raw-batches.return.form');
Route::post('raw-batches/{batch}/return', [RawMaterialBatchController::class, 'return'])
    ->name('raw-batches.return');

// Маршруты для приемок
Route::resource('stone-receptions', StoneReceptionController::class);
Route::post('stone-receptions/{stoneReception}/copy', [StoneReceptionController::class, 'copy'])
    ->name('stone-receptions.copy');



require __DIR__.'/auth.php';
