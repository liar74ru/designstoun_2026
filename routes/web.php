<?php

use App\Http\Controllers\CounterpartyController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RawMaterialBatchController;
use App\Http\Controllers\WorkshopController;
use App\Http\Controllers\StoneReceptionController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SupplierOrderController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\Admin\OrderStatusSettingController;
use App\Http\Controllers\Admin\WorkshopPresetController;
use App\Http\Controllers\AdminSettingController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\CutterWorkerDashboardController;
use App\Http\Controllers\EnterpriseDashboardController;
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

    // Дашборды — свои защищены Gate'ом, *.by-id защищены Policy в контроллере
    Route::get('/my-work', [CutterWorkerDashboardController::class, 'showWorker'])
        ->middleware('can:see-worker-dashboard')
        ->name('worker.dashboard');
    Route::get('/master-work', [CutterWorkerDashboardController::class, 'showMaster'])
        ->middleware('can:see-master-dashboard')
        ->name('master.dashboard');
    Route::get('/master-work/{workerId}', [CutterWorkerDashboardController::class, 'showMaster'])
        ->name('master.dashboard.by-id');
    Route::get('/workers/{workerId}/dashboard', [CutterWorkerDashboardController::class, 'showWorker'])
        ->name('worker.dashboard.by-id');

    // Товары — операция products
    Route::middleware('can:see-products')->group(function () {
        Route::resource('products', ProductController::class)->only(['index', 'show']);
        Route::get('/products/sync/moysklad', [ProductController::class, 'syncFromMoySklad'])->name('products.sync');
        Route::get('/products/{id}/refresh', [ProductController::class, 'refresh'])->name('products.refresh');
        Route::post('/products/stocks/sync-all-by-stores', [ProductController::class, 'syncAllProductsStocks'])->name('products.stocks.sync-all-by-stores');
        Route::get('/products/groups/tree', [ProductController::class, 'groups'])->name('products.groups');
        Route::get('/products/groups/sync', [ProductController::class, 'syncGroups'])->name('products.groups.sync');
        Route::post('/products/{moyskladId}/stocks-sync', [ProductController::class, 'syncStocks'])->name('products.stocks.sync');
    });

    // Заявки — операция orders
    Route::middleware('can:see-orders')->group(function () {
        Route::get ('orders',       [OrderController::class, 'index'])->name('orders.index');
        Route::post('orders/sync',  [OrderController::class, 'sync'])->name('orders.sync');
    });

    // Работники — список/CRUD доступен по can:see-workers; смена своего пароля — отдельно
    Route::resource('workers', WorkerController::class)
        ->except(['show'])
        ->middleware('can:see-workers');
    Route::get('/workers/{worker}/create-user', [WorkerController::class, 'createUser'])
        ->middleware('can:see-workers')
        ->name('workers.create-user');
    Route::post('/workers/{worker}/store-user', [WorkerController::class, 'storeUser'])
        ->middleware('can:see-workers')
        ->name('workers.store-user');
    Route::patch('/workers/{worker}/archive', [WorkerController::class, 'archive'])
        ->middleware('can:see-workers')
        ->name('workers.archive');
    Route::patch('/workers/{worker}/restore', [WorkerController::class, 'restore'])
        ->middleware('can:see-workers')
        ->name('workers.restore');

    // Свой пароль — доступен любому залогиненному (Policy на конкретного worker)
    Route::get('/workers/{worker}/edit-user', [WorkerController::class, 'editUser'])->name('workers.edit-user');
    Route::put('/workers/{worker}/update-user', [WorkerController::class, 'updateUser'])->name('workers.update-user');

    // Синхронизация — админ
    Route::middleware('can:manage-admin')->group(function () {
        Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');

        Route::get('/counterparties', [CounterpartyController::class, 'index'])->name('counterparties.index');
        Route::post('/counterparties/sync', [CounterpartyController::class, 'sync'])->name('counterparties.sync');

        Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
        Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');
        Route::post('/stores/sync', [StoreController::class, 'sync'])->name('stores.sync');
        Route::post('/stores/stocks/sync-all', [StoreController::class, 'syncAllStocks'])->name('stores.stocks.sync-all');
        Route::post('/stores/{store}/stocks-sync', [StoreController::class, 'syncStoreStocks'])->name('stores.stocks.sync');
    });

    // Поступления сырья — операция supplier-orders
    Route::middleware('can:see-supplier-orders')->group(function () {
        Route::get('/supplier-orders', [SupplierOrderController::class, 'index'])->name('supplier-orders.index');
        Route::get('/supplier-orders/create', [SupplierOrderController::class, 'create'])->name('supplier-orders.create');
        Route::get('/supplier-orders/{supplierOrder}', [SupplierOrderController::class, 'show'])->name('supplier-orders.show');
        Route::post('/supplier-orders', [SupplierOrderController::class, 'store'])->name('supplier-orders.store');
        Route::get('/supplier-orders/{supplierOrder}/edit', [SupplierOrderController::class, 'edit'])->name('supplier-orders.edit');
        Route::put('/supplier-orders/{supplierOrder}', [SupplierOrderController::class, 'update'])->name('supplier-orders.update');
        Route::delete('/supplier-orders/{supplierOrder}', [SupplierOrderController::class, 'destroy'])->name('supplier-orders.destroy');
        Route::post('/supplier-orders/{supplierOrder}/sync', [SupplierOrderController::class, 'sync'])->name('supplier-orders.sync');
        Route::get('/supplier-orders/{supplierOrder}/sync-confirm', [SupplierOrderController::class, 'syncConfirm'])->name('supplier-orders.sync-confirm');
        Route::post('/supplier-orders/{supplierOrder}/force-sync', [SupplierOrderController::class, 'forceSync'])->name('supplier-orders.force-sync');
        Route::get('/api/supplier-orders/next-number', [SupplierOrderController::class, 'nextOrderNumber'])->name('api.supplier-orders.next-number');
    });

    // Приёмки камня — операция stone-receptions
    Route::middleware('can:see-stone-receptions')->group(function () {
        Route::resource('stone-receptions', StoneReceptionController::class);
        Route::get('/stone-receptions-logs', [StoneReceptionController::class, 'logs'])->name('stone-receptions.logs');
        Route::post('stone-receptions/{stoneReception}/copy', [StoneReceptionController::class, 'copy'])->name('stone-receptions.copy');
        Route::patch('/stone-receptions/{stoneReception}/reset-status', [StoneReceptionController::class, 'resetStatus'])->name('stone-receptions.reset-status');
        Route::patch('/stone-receptions/{stoneReception}/mark-completed', [StoneReceptionController::class, 'markCompleted'])->name('stone-receptions.mark-completed');
        Route::patch('/stone-receptions/{stoneReception}/update-store', [StoneReceptionController::class, 'updateStore'])->name('stone-receptions.update-store');
        Route::post('/stone-receptions/{stoneReception}/sync', [StoneReceptionController::class, 'syncToProcessing'])->name('stone-receptions.sync');
        Route::post('/stone-receptions/{stoneReception}/item-coeffs', [StoneReceptionController::class, 'updateItemCoeff'])->name('stone-receptions.update-item-coeff');
        Route::post('/stone-receptions/{stoneReception}/refresh-item-coeffs', [StoneReceptionController::class, 'refreshItemCoeffs'])->name('stone-receptions.refresh-item-coeffs');

        // Правка приёмщика в записи журнала — только админ (ретроспективная атрибуция выработки)
        Route::patch('/reception-logs/{receptionLog}/receiver', [StoneReceptionController::class, 'updateLogReceiver'])
            ->name('reception-logs.update-receiver')
            ->middleware('can:manage-admin');

        // AJAX-эндпоинты, относящиеся к приёмкам
        Route::get('/api/workers/{worker}/batches', [StoneReceptionController::class, 'getBatchesJson'])->name('api.worker.batches');
        Route::get('/api/batches/{batch}/receptions', [StoneReceptionController::class, 'getReceptionsByBatchJson'])->name('api.batch.receptions');
        Route::get('/api/batches/{batch}/active-reception', [StoneReceptionController::class, 'getActiveReceptionByBatchJson'])->name('api.batch.active-reception');
    });

    // Цех — операция workshops
    Route::redirect('/packagings', '/workshops');
    Route::middleware('can:see-workshops')->group(function () {
        Route::resource('workshops', WorkshopController::class);
        Route::post  ('workshops/{workshop}/copy',                [WorkshopController::class, 'copy'])->name('workshops.copy');
        Route::patch ('workshops/{workshop}/reset-status',        [WorkshopController::class, 'resetStatus'])->name('workshops.reset-status');
        Route::patch ('workshops/{workshop}/mark-completed',      [WorkshopController::class, 'markCompleted'])->name('workshops.mark-completed');
        Route::post  ('workshops/{workshop}/sync',                [WorkshopController::class, 'syncToProcessing'])->name('workshops.sync');
        Route::post  ('workshops/{workshop}/item-coeffs',         [WorkshopController::class, 'updateItemCoeff'])->name('workshops.update-item-coeff');
        Route::post  ('workshops/{workshop}/refresh-item-coeffs', [WorkshopController::class, 'refreshItemCoeffs'])->name('workshops.refresh-item-coeffs');
        Route::get   ('api/workers/{worker}/default-production-store', [WorkshopController::class, 'getDefaultStoreJson'])->name('api.worker.default-production-store');
        Route::get   ('api/departments/{department}/workshop-presets', [WorkshopController::class, 'getPresetsJson'])->name('api.department.workshop-presets');
    });

    // Партии сырья — операция raw-batches
    Route::middleware('can:see-raw-batches')->group(function () {
        Route::resource('raw-batches', RawMaterialBatchController::class)->parameters(['raw-batches' => 'batch']);
        Route::delete('raw-batches/{batch}/new', [RawMaterialBatchController::class, 'destroyNew'])->name('raw-batches.destroy-new');
        Route::get('raw-batches/{batch}/copy', [RawMaterialBatchController::class, 'copy'])->name('raw-batches.copy');
        Route::get('raw-batches/{batch}/transfer', [RawMaterialBatchController::class, 'transferForm'])->name('raw-batches.transfer.form');
        Route::post('raw-batches/{batch}/transfer', [RawMaterialBatchController::class, 'transfer'])->name('raw-batches.transfer');
        Route::get('raw-batches/{batch}/return', [RawMaterialBatchController::class, 'returnForm'])->name('raw-batches.return.form');
        Route::post('raw-batches/{batch}/return', [RawMaterialBatchController::class, 'return'])->name('raw-batches.return');
        Route::get('raw-batches/{batch}/adjust', [RawMaterialBatchController::class, 'adjustForm'])->name('raw-batches.adjust.form');
        Route::post('raw-batches/{batch}/adjust', [RawMaterialBatchController::class, 'adjust'])->name('raw-batches.adjust');
        Route::post('raw-batches/{batch}/archive', [RawMaterialBatchController::class, 'archive'])->name('raw-batches.archive');
        Route::post('raw-batches/{batch}/mark-used', [RawMaterialBatchController::class, 'markAsUsed'])->name('raw-batches.mark-used');
        Route::post('raw-batches/{batch}/mark-in-work', [RawMaterialBatchController::class, 'markAsInWork'])->name('raw-batches.mark-in-work');
        Route::post('raw-batches/{batch}/sync', [RawMaterialBatchController::class, 'syncBatch'])->name('raw-batches.sync');
        Route::get('/api/workers/{worker}/next-batch-number', [RawMaterialBatchController::class, 'nextBatchNumber'])->name('api.worker.next-batch-number');
        Route::get('/api/workers/{worker}/batch-by-product', [RawMaterialBatchController::class, 'workerBatchByProduct'])->name('api.worker.batch-by-product');
    });

    // AJAX-эндпоинты товаров — для всех с правами на работу с товарами/приёмками
    Route::get('/api/products/tree', [ProductController::class, 'groupsJson'])->name('api.products.tree');
    Route::get('/api/products/stocks', [ProductController::class, 'stocksJson'])->name('api.products.stocks');
    Route::get('/api/products/{product}/coeff', [ProductController::class, 'getCoeff'])->name('api.products.coeff');

    // Настройки системы — только админ (через can:manage-admin на группе)
    Route::prefix('admin')->name('admin.')->middleware('can:manage-admin')->group(function () {
        Route::get('/enterprise-dashboard', [EnterpriseDashboardController::class, 'index'])->name('enterprise-dashboard');
        Route::get('/settings',  [AdminSettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [AdminSettingController::class, 'update'])->name('settings.update');
        Route::post('/departments/store-defaults', [AdminSettingController::class, 'updateDepartmentStores'])->name('departments.store-defaults');
        Route::patch('/departments/{department}/operations', [DepartmentController::class, 'updateOperations'])->name('departments.operations.update');
        Route::resource('departments', DepartmentController::class)->only(['create', 'store', 'show', 'update', 'destroy']);

        // Пресеты цеха отдела
        Route::scopeBindings()->group(function () {
            Route::get   ('/departments/{department}/presets/create',        [WorkshopPresetController::class, 'create'])->name('departments.presets.create');
            Route::post  ('/departments/{department}/presets',               [WorkshopPresetController::class, 'store'])->name('departments.presets.store');
            Route::get   ('/departments/{department}/presets/{preset}/edit', [WorkshopPresetController::class, 'edit'])->name('departments.presets.edit');
            Route::patch ('/departments/{department}/presets/{preset}',      [WorkshopPresetController::class, 'update'])->name('departments.presets.update');
            Route::delete('/departments/{department}/presets/{preset}',      [WorkshopPresetController::class, 'destroy'])->name('departments.presets.destroy');
            Route::post  ('/departments/{department}/presets/{preset}/copy', [WorkshopPresetController::class, 'copy'])->name('departments.presets.copy');
        });

        Route::get ('/order-statuses', [OrderStatusSettingController::class, 'index'])->name('order-statuses.index');
        Route::post('/order-statuses', [OrderStatusSettingController::class, 'update'])->name('order-statuses.update');
    });
});

require __DIR__.'/auth.php';
