<?php

use App\Http\Controllers\MoySkladController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

// Главная страница ведет на товары
Route::get('/', [ProductController::class, 'index'])->name('home');

// Ресурсные маршруты для товаров (все методы: index, create, store, show, edit, update, destroy)
Route::resource('products', ProductController::class);

// Ресурсные маршруты для заказов (все методы: index, create, store, show, edit, update, destroy)
Route::resource('orders', OrderController::class);

// Дополнительные кастомные маршруты для синхронизации с МойСклад
Route::prefix('moysklad')->name('moysklad.')->group(function () {
    Route::get('/sync/products', [ProductController::class, 'syncFromMoySklad'])->name('sync.products');
    Route::get('/sync/orders', [OrderController::class, 'syncFromMoySklad'])->name('sync.orders');
    Route::get('/orders', [MoySkladController::class, 'getOrders'])->name('orders');
});
