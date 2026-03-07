<?php

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Store;

// ──────────────────────────────────────────────────────────────────────────────
// calculateWorkerPay — расчёт зарплаты пильщика
// ──────────────────────────────────────────────────────────────────────────────

test('calculateWorkerPay возвращает 0 если коэффициент не задан', function () {
    $product = new Product(['prod_cost_coeff' => null]);
    expect($product->calculateWorkerPay(10))->toBe(0.0);
});

test('calculateWorkerPay считает по формуле: кол-во × коэфф × 390', function () {
    $product = new Product(['prod_cost_coeff' => 1.5]);
    expect($product->calculateWorkerPay(5))->toBe(2925.0);
});

test('calculateWorkerPay с коэффициентом 1.0 возвращает кол-во × 390', function () {
    $product = new Product(['prod_cost_coeff' => 1.0]);
    expect($product->calculateWorkerPay(10))->toBe(3900.0);
});

test('calculateWorkerPay с нулевым количеством возвращает 0', function () {
    $product = new Product(['prod_cost_coeff' => 2.0]);
    expect($product->calculateWorkerPay(0))->toBe(0.0);
});

// ──────────────────────────────────────────────────────────────────────────────
// has_discount / discount_percent — чисто вычислительные, без БД
// ──────────────────────────────────────────────────────────────────────────────

test('has_discount true если old_price больше price', function () {
    $product = new Product(['price' => 100, 'old_price' => 150]);
    expect($product->has_discount)->toBeTrue();
});

test('has_discount false если old_price не задан', function () {
    $product = new Product(['price' => 100, 'old_price' => null]);
    expect($product->has_discount)->toBeFalse();
});

test('discount_percent считается правильно', function () {
    $product = new Product(['price' => 750, 'old_price' => 1000]);
    expect($product->discount_percent)->toBe(25);
});
