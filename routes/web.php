<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkerController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MoySkladController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;

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

// Ресурсные маршруты для заказов (все методы: index, create, store, show, edit, update, destroy)
Route::resource('orders', OrderController::class);

// Дополнительные кастомные маршруты для синхронизации с МойСклад
Route::prefix('moysklad')->name('moysklad.')->group(function () {
    Route::get('/sync/products', [ProductController::class, 'syncFromMoySklad'])->name('sync.products');
    Route::get('/sync/orders', [OrderController::class, 'syncFromMoySklad'])->name('sync.orders');
    Route::get('/orders', [MoySkladController::class, 'getOrders'])->name('orders');
});

// Ресурсный маршрут для работников
Route::resource('workers', WorkerController::class)->except([
    'show'
]);

Route::get('/products/groups/tree', [ProductController::class, 'groups'])
    ->name('products.groups');


require __DIR__.'/auth.php';
