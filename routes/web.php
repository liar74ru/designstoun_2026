<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RawMaterialBatchController;
use App\Http\Controllers\RawMaterialMovementController;
use App\Http\Controllers\StoneReceptionBatchController;
use App\Http\Controllers\StoneReceptionController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\WorkerDashboardController;
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

    // Личная страница работника — видит себя
    Route::get('/my-work', [WorkerDashboardController::class, 'show'])->name('worker.dashboard');

    // Страница работника для администратора — смотрит любого
    Route::get('/workers/{workerId}/dashboard', [WorkerDashboardController::class, 'show'])
        ->name('worker.dashboard.by-id');
});

// Ресурсные маршруты для товаров
Route::resource('products', ProductController::class)->except(['edit']);

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

Route::resource('orders', OrderController::class);

Route::resource('workers', WorkerController::class)->except(['show']);

Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');
Route::post('/stores/sync', [StoreController::class, 'sync'])->name('stores.sync');
Route::post('/stores/stocks/sync-all', [StoreController::class, 'syncAllStocks'])
    ->name('stores.stocks.sync-all');
Route::post('/stores/{store}/stocks-sync', [StoreController::class, 'syncStoreStocks'])
    ->name('stores.stocks.sync');

Route::resource('stone-receptions', StoneReceptionController::class);
Route::post('stone-receptions/{stoneReception}/copy', [StoneReceptionController::class, 'copy'])
    ->name('stone-receptions.copy');

Route::resource('raw-batches', RawMaterialBatchController::class)->except(['edit', 'update', 'store']);
Route::post('raw-batches', [RawMaterialBatchController::class, 'store'])->name('raw-batches.store');
Route::get('raw-batches/{batch}/copy', [RawMaterialBatchController::class, 'copy'])
    ->name('raw-batches.copy');
Route::get('raw-batches/{batch}/transfer', [RawMaterialBatchController::class, 'transferForm'])
    ->name('raw-batches.transfer.form');
Route::post('raw-batches/{batch}/transfer', [RawMaterialBatchController::class, 'transfer'])
    ->name('raw-batches.transfer');
Route::get('raw-batches/{batch}/return', [RawMaterialBatchController::class, 'returnForm'])
    ->name('raw-batches.return.form');
Route::post('raw-batches/{batch}/return', [RawMaterialMovementController::class, 'return'])
    ->name('raw-batches.return');
Route::post('raw-batches/create', [RawMaterialMovementController::class, 'store'])
    ->name('raw-movement.store');

Route::middleware(['auth'])->group(function () {
    Route::post('/stone-receptions/batch/send-to-processing',
        [StoneReceptionBatchController::class, 'sendToProcessing'])
        ->name('stone-receptions.batch.send-to-processing');
    Route::get('/stone-receptions/batch/stats',
        [StoneReceptionBatchController::class, 'getStats'])
        ->name('stone-receptions.batch.stats');
    Route::patch('/stone-receptions/{stoneReception}/reset-status',
        [StoneReceptionController::class, 'resetStatus'])
        ->name('stone-receptions.reset-status');
    Route::get('/products/groups/sync', [ProductController::class, 'syncGroups'])
        ->name('products.groups.sync');
});

require __DIR__.'/auth.php';

// AJAX-эндпоинты для формы приёмки
Route::middleware('auth')->group(function () {
    // Партии сырья для конкретного пильщика (JSON)
    Route::get('/api/workers/{worker}/batches', [StoneReceptionController::class, 'getBatchesJson'])
        ->name('api.worker.batches');
    // Следующий номер партии для пильщика
    Route::get('/api/workers/{worker}/next-batch-number', [RawMaterialBatchController::class, 'nextBatchNumber'])
        ->name('api.worker.next-batch-number');
    // Дерево групп с продуктами (JSON, кэшируется)
    Route::get('/api/products/tree', [ProductController::class, 'groupsJson'])
        ->name('api.products.tree');
});
