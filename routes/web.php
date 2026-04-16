<?php

use App\Http\Controllers\CounterpartyController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RawMaterialBatchController;
use App\Http\Controllers\StoneReceptionController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SupplierOrderController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\AdminSettingController;
use App\Http\Controllers\WorkerDashboardController;
use App\Http\Controllers\MasterDashboardController;
use Illuminate\Support\Facades\Route;

// Главная — редирект на login если не авторизован
Route::get('/', function () {
    return redirect()->route('login');
});

// Всё остальное — только для авторизованных
Route::middleware(['auth'])->group(function () {

    Route::get('/', function () {
        if (auth()->user()?->isMaster()) {
            return redirect()->route('master.dashboard');
        }
        return view('home');
    })->name('home');

    // Профиль

    // Страница работника
    Route::get('/my-work', [WorkerDashboardController::class, 'show'])->name('worker.dashboard');
    Route::get('/master-work', [MasterDashboardController::class, 'show'])->name('master.dashboard');
    Route::get('/master-work/{workerId}', [MasterDashboardController::class, 'show'])->name('master.dashboard.by-id');
    Route::get('/workers/{workerId}/dashboard', [WorkerDashboardController::class, 'show'])
        ->name('worker.dashboard.by-id');

    // Товары
    Route::resource('products', ProductController::class)->only(['index', 'show']);
    Route::get('/products/sync/moysklad', [ProductController::class, 'syncFromMoySklad'])->name('products.sync');
    Route::get('/products/{id}/refresh', [ProductController::class, 'refresh'])->name('products.refresh');
    Route::post('/products/stocks/sync-all-by-stores', [ProductController::class, 'syncAllProductsStocks'])->name('products.stocks.sync-all-by-stores');
    Route::get('/products/groups/tree', [ProductController::class, 'groups'])->name('products.groups');
    Route::get('/products/groups/sync', [ProductController::class, 'syncGroups'])->name('products.groups.sync');
    Route::post('/products/{moyskladId}/stocks-sync', [ProductController::class, 'syncStocks'])->name('products.stocks.sync');

    // Заказы
    Route::resource('orders', OrderController::class);

    // Работники
    Route::resource('workers', WorkerController::class)->except(['show']);
    Route::get('/workers/{worker}/create-user', [WorkerController::class, 'createUser'])->name('workers.create-user');
    Route::post('/workers/{worker}/store-user', [WorkerController::class, 'storeUser'])->name('workers.store-user');
    Route::get('/workers/{worker}/edit-user', [WorkerController::class, 'editUser'])->name('workers.edit-user');
    Route::put('/workers/{worker}/update-user', [WorkerController::class, 'updateUser'])->name('workers.update-user');

    // Синхронизация
    Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');

    // Контрагенты
    Route::get('/counterparties', [CounterpartyController::class, 'index'])->name('counterparties.index');
    Route::post('/counterparties/sync', [CounterpartyController::class, 'sync'])->name('counterparties.sync');

    // Поступления сырья
    Route::get('/supplier-orders', [SupplierOrderController::class, 'index'])->name('supplier-orders.index');
    Route::get('/supplier-orders/create', [SupplierOrderController::class, 'create'])->name('supplier-orders.create');
    Route::post('/supplier-orders', [SupplierOrderController::class, 'store'])->name('supplier-orders.store');
    Route::get('/supplier-orders/{supplierOrder}/edit', [SupplierOrderController::class, 'edit'])->name('supplier-orders.edit');
    Route::put('/supplier-orders/{supplierOrder}', [SupplierOrderController::class, 'update'])->name('supplier-orders.update');
    Route::delete('/supplier-orders/{supplierOrder}', [SupplierOrderController::class, 'destroy'])->name('supplier-orders.destroy');
    Route::post('/supplier-orders/{supplierOrder}/sync', [SupplierOrderController::class, 'sync'])->name('supplier-orders.sync');
    Route::get('/supplier-orders/{supplierOrder}/sync-confirm', [SupplierOrderController::class, 'syncConfirm'])->name('supplier-orders.sync-confirm');
    Route::post('/supplier-orders/{supplierOrder}/force-sync', [SupplierOrderController::class, 'forceSync'])->name('supplier-orders.force-sync');
    Route::get('/api/supplier-orders/next-number', [SupplierOrderController::class, 'nextOrderNumber'])->name('api.supplier-orders.next-number');

    // Склады
    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
    Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');
    Route::post('/stores/sync', [StoreController::class, 'sync'])->name('stores.sync');
    Route::post('/stores/stocks/sync-all', [StoreController::class, 'syncAllStocks'])->name('stores.stocks.sync-all');
    Route::post('/stores/{store}/stocks-sync', [StoreController::class, 'syncStoreStocks'])->name('stores.stocks.sync');

    // Приёмки камня
    Route::resource('stone-receptions', StoneReceptionController::class);
    Route::get('/stone-receptions-logs', [StoneReceptionController::class, 'logs'])->name('stone-receptions.logs');
    Route::post('stone-receptions/{stoneReception}/copy', [StoneReceptionController::class, 'copy'])->name('stone-receptions.copy');
    Route::patch('/stone-receptions/{stoneReception}/reset-status', [StoneReceptionController::class, 'resetStatus'])->name('stone-receptions.reset-status');
    Route::patch('/stone-receptions/{stoneReception}/mark-completed', [StoneReceptionController::class, 'markCompleted'])->name('stone-receptions.mark-completed');
    Route::post('/stone-receptions/{stoneReception}/sync', [StoneReceptionController::class, 'syncToProcessing'])->name('stone-receptions.sync');

    // Партии сырья
    Route::resource('raw-batches', RawMaterialBatchController::class)->parameters(['raw-batches' => 'batch']);
    Route::delete('raw-batches/{batch}/new', [RawMaterialBatchController::class, 'destroyNew'])->name('raw-batches.destroy-new');
    Route::get('raw-batches/{batch}/copy', [RawMaterialBatchController::class, 'copy'])->name('raw-batches.copy');
    Route::get('raw-batches/{batch}/transfer', [RawMaterialBatchController::class, 'transferForm'])->name('raw-batches.transfer.form');
    Route::post('raw-batches/{batch}/transfer', [RawMaterialBatchController::class, 'transfer'])->name('raw-batches.transfer');
    Route::get('raw-batches/{batch}/return', [RawMaterialBatchController::class, 'returnForm'])->name('raw-batches.return.form');
    Route::post('raw-batches/{batch}/return', [RawMaterialBatchController::class, 'return'])->name('raw-batches.return');
    Route::get('raw-batches/{batch}/adjust', [RawMaterialBatchController::class, 'adjustForm'])->name('raw-batches.adjust.form');
    Route::post('raw-batches/{batch}/adjust', [RawMaterialBatchController::class, 'adjust'])->name('raw-batches.adjust');
    Route::get('raw-batches/{batch}/adjust-remaining', [RawMaterialBatchController::class, 'adjustRemainingForm'])->name('raw-batches.adjust-remaining.form');
    Route::post('raw-batches/{batch}/adjust-remaining', [RawMaterialBatchController::class, 'adjustRemaining'])->name('raw-batches.adjust-remaining');
    Route::post('raw-batches/{batch}/archive', [RawMaterialBatchController::class, 'archive'])->name('raw-batches.archive');
    Route::post('raw-batches/{batch}/mark-used', [RawMaterialBatchController::class, 'markAsUsed'])->name('raw-batches.mark-used');
    Route::post('raw-batches/{batch}/mark-in-work', [RawMaterialBatchController::class, 'markAsInWork'])->name('raw-batches.mark-in-work');
    Route::post('raw-batches/{batch}/sync', [RawMaterialBatchController::class, 'syncBatch'])->name('raw-batches.sync');

    // AJAX-эндпоинты
    Route::get('/api/workers/{worker}/batches', [StoneReceptionController::class, 'getBatchesJson'])->name('api.worker.batches');
    Route::get('/api/batches/{batch}/receptions', [StoneReceptionController::class, 'getReceptionsByBatchJson'])->name('api.batch.receptions');
    Route::get('/api/batches/{batch}/active-reception', [StoneReceptionController::class, 'getActiveReceptionByBatchJson'])->name('api.batch.active-reception');
    Route::get('/api/workers/{worker}/next-batch-number', [RawMaterialBatchController::class, 'nextBatchNumber'])->name('api.worker.next-batch-number');
    Route::get('/api/products/tree', [ProductController::class, 'groupsJson'])->name('api.products.tree');
    Route::get('/api/products/stocks', [ProductController::class, 'stocksJson'])->name('api.products.stocks');
    Route::get('/api/products/{product}/coeff', [ProductController::class, 'getCoeff'])->name('api.products.coeff');

    // Редактирование коэффициентов позиций приёмки (из страницы show)
    Route::post('/stone-receptions/{stoneReception}/item-coeffs', [StoneReceptionController::class, 'updateItemCoeff'])->name('stone-receptions.update-item-coeff');
    Route::post('/stone-receptions/{stoneReception}/refresh-item-coeffs', [StoneReceptionController::class, 'refreshItemCoeffs'])->name('stone-receptions.refresh-item-coeffs');

    // Настройки системы (только администратор — проверка в контроллере)
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/settings',  [AdminSettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [AdminSettingController::class, 'update'])->name('settings.update');
    });
});

require __DIR__.'/auth.php';
