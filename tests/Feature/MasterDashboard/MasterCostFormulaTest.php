<?php

use App\Models\Department;
use App\Models\DepartmentSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StoneReceptionItem;
use Illuminate\Support\Facades\Cache;

// ══════════════════════════════════════════════════════════════════════════════
// StoneReceptionItem::computeMasterCost() — ставка мастера по коэффициенту продукта
// ══════════════════════════════════════════════════════════════════════════════

beforeEach(function () {
    Cache::flush();
    Setting::set('MASTER_BASE_RATE', '100');
    Setting::set('MASTER_UNDERCUT_RATE', '50');
    $this->dept = Department::create(['name' => 'Отдел Мастера', 'is_active' => true]);
});

test('коэффициент 0 даёт базовую ставку — поведение до перехода сохраняется', function () {
    $product = Product::factory()->create(['master_cost_coeff' => 0]);

    expect(StoneReceptionItem::computeMasterCost(false, $this->dept->id, $product))->toBe(100.0);
});

test('продукт без коэффициента (null) приравнивается к 0', function () {
    $product = Product::factory()->create(['master_cost_coeff' => null]);

    expect(StoneReceptionItem::computeMasterCost(false, $this->dept->id, $product))->toBe(100.0);
});

test('продукт не передан — базовая ставка', function () {
    expect(StoneReceptionItem::computeMasterCost(false, $this->dept->id))->toBe(100.0);
});

test('коэффициент 1 при базе 100 даёт 110', function () {
    $product = Product::factory()->create(['master_cost_coeff' => 1]);

    expect(StoneReceptionItem::computeMasterCost(false, $this->dept->id, $product))->toBe(110.0);
});

test('коэффициент 3 воспроизводит прежнюю надбавку за мелкую плитку (150)', function () {
    $product = Product::factory()->create(['master_cost_coeff' => 3]);

    expect(StoneReceptionItem::computeMasterCost(false, $this->dept->id, $product))->toBe(150.0);
});

test('надбавка за подкол применяется ПОСЛЕ округления по 10', function () {
    $product = Product::factory()->create(['master_cost_coeff' => 1]);

    // floor((100 + 17)/10)*10 = 110, затем +50 = 160
    expect(StoneReceptionItem::computeMasterCost(true, $this->dept->id, $product))->toBe(160.0);
});

test('MASTER_BASE_RATE отдела перекрывает глобальный', function () {
    DepartmentSetting::create([
        'department_id' => $this->dept->id,
        'key'           => 'MASTER_BASE_RATE',
        'value'         => '200',
    ]);
    $product = Product::factory()->create(['master_cost_coeff' => 0]);

    expect(StoneReceptionItem::computeMasterCost(false, $this->dept->id, $product))->toBe(200.0);
    expect(StoneReceptionItem::computeMasterCost(false, null, $product))->toBe(100.0);
});

test('MASTER_UNDERCUT_RATE отдела перекрывает глобальный', function () {
    DepartmentSetting::create([
        'department_id' => $this->dept->id,
        'key'           => 'MASTER_UNDERCUT_RATE',
        'value'         => '80',
    ]);
    $product = Product::factory()->create(['master_cost_coeff' => 0]);

    expect(StoneReceptionItem::computeMasterCost(true, $this->dept->id, $product))->toBe(180.0);
});

test('department_id = null → глобальные настройки', function () {
    $product = Product::factory()->create(['master_cost_coeff' => 0]);

    expect(StoneReceptionItem::computeMasterCost(false, null, $product))->toBe(100.0);
    expect(StoneReceptionItem::computeMasterCost(true, null, $product))->toBe(150.0);
});
