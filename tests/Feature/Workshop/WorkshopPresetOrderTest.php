<?php

use App\Models\Department;
use App\Models\WorkshopItem;
use App\Models\WorkshopPreset;
use App\Services\WorkshopPresetService;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

// ══════════════════════════════════════════════════════════════════════════════
// Порядок пресетов отдела (карточка отдела, «Шаблоны» формы цеха): по SKU первого
// продукта пресета, при равном SKU — по имени, пресеты без продукта — в конце.
// ══════════════════════════════════════════════════════════════════════════════

beforeEach(function () {
    Cache::flush();
    $this->dept = Department::create(['name' => 'Цех', 'is_active' => true]);
});

/** Пресет отдела; $sku — продукт пресета, null — без продукта (только сырьё). */
function orderedPreset(Department $dept, string $name, ?string $sku): WorkshopPreset
{
    $preset = WorkshopPreset::create(['department_id' => $dept->id, 'name' => $name]);
    $preset->items()->create(['product_id' => H::product(['sku' => '04-01-10'])->id, 'role' => WorkshopItem::ROLE_RAW, 'quantity' => 1]);
    if ($sku !== null) {
        $preset->items()->create(['product_id' => H::product(['sku' => $sku])->id, 'role' => WorkshopItem::ROLE_PRODUCT, 'quantity' => 1]);
    }

    return $preset;
}

test('пресеты идут по SKU продукта, при равном SKU — по имени, без продукта — в конце', function () {
    orderedPreset($this->dept, 'Аа без продукта', null);
    orderedPreset($this->dept, 'Яблоко', '05-01-01');
    orderedPreset($this->dept, 'Бордюр', '01-02-03');
    orderedPreset($this->dept, 'Плитка', '03-01-01');
    orderedPreset($this->dept, 'Арка', '03-01-01');

    $names = app(WorkshopPresetService::class)->getForDepartment($this->dept)->pluck('name')->all();

    expect($names)->toBe(['Бордюр', 'Арка', 'Плитка', 'Яблоко', 'Аа без продукта']);
});

test('порядок решает SKU продукта, а не сырья', function () {
    $preset = orderedPreset($this->dept, 'Первый по сырью', '09-01-01');
    $preset->items()->where('role', WorkshopItem::ROLE_RAW)->first()->product->update(['sku' => '00-00-01']);
    orderedPreset($this->dept, 'Второй', '02-01-01');

    $names = app(WorkshopPresetService::class)->getForDepartment($this->dept)->pluck('name')->all();

    expect($names)->toBe(['Второй', 'Первый по сырью']);
});
