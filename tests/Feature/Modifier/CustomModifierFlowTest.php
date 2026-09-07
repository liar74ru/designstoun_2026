<?php

use App\Models\DepartmentModifier;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoneReceptionItem;
use App\Models\Worker;
use App\Services\DepartmentModifierService;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\Moysklad\StoneReceptionSyncService;
use App\Services\RawMaterialBatchService;
use App\Services\StoneReceptionService;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Сквозной путь: правило, заведённое администратором в карточке отдела,
 * доходит до формы приёмки и до расчёта зарплаты. До этапа 4 формы знали
 * ровно два захардкоженных правила, и своё правило админа не работало.
 */

beforeEach(function () {
    Cache::flush();
    Setting::updateOrCreate(['key' => 'PIECE_RATE'], ['value' => '100']);

    $this->dept = H::departmentWithModifiers();
    $this->rule = app(DepartmentModifierService::class)->create($this->dept, [
        'key'                => 'chipped_edge',
        'name'               => 'Скол края',
        'color'              => '#DC3545',
        'trigger'            => DepartmentModifier::TRIGGER_MANUAL,
        'applies_to'         => DepartmentModifier::SCOPE_BOTH,
        'worker_coeff_delta' => -1.0,
        'is_active'          => true,
    ]);
});

function customFlowService(): StoneReceptionService
{
    $sync = Mockery::mock(StoneReceptionSyncService::class);
    $sync->shouldReceive('syncReception')->andReturn(null);

    $batchSync = Mockery::mock(RawMaterialBatchSyncService::class);
    $batchSync->shouldReceive('syncCreated')->andReturn(null);
    $batchSync->shouldReceive('updateParentMove')->andReturn(null);

    return new StoneReceptionService($sync, app(RawMaterialBatchService::class), $batchSync);
}

function customFlowReception(array $modifiers, float $coeff = 3.0): StoneReceptionItem
{
    $rawProduct = Product::factory()->create(['sku' => '04-01']);
    $product    = Product::factory()->create(['sku' => '04-01-20', 'prod_cost_coeff' => $coeff]);
    $store      = Store::factory()->create();
    $cutter     = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
    $receiver   = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);

    $batch = RawMaterialBatch::create([
        'product_id'         => $rawProduct->id,
        'initial_quantity'   => 100.0,
        'remaining_quantity' => 100.0,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $cutter->id,
        'department_id'      => test()->dept->id,
        'status'             => RawMaterialBatch::STATUS_IN_WORK,
    ]);

    return customFlowService()->create([
        'receiver_id'           => $receiver->id,
        'cutter_id'             => $cutter->id,
        'store_id'              => $store->id,
        'raw_material_batch_id' => $batch->id,
        'raw_quantity_used'     => 5.0,
        'products' => [
            ['product_id' => $product->id, 'quantity' => 2.0, 'modifiers' => $modifiers],
        ],
    ], false)->items->first();
}

test('своё правило отдела применяется к коэффициенту и ставке', function () {
    $item = customFlowReception(['chipped_edge']);

    // База 3.0, правило −1.0
    expect((float) $item->effective_cost_coeff)->toBe(2.0)
        ->and((float) $item->worker_cost_per_m2)->toBe(130.0)
        ->and($item->modifiers->pluck('key')->all())->toBe(['chipped_edge']);
});

test('несколько правил в одной позиции складываются', function () {
    $item = customFlowReception(['chipped_edge', 'undercut']);

    // 3.0 − 1.0 − 1.5 = 0.5
    expect((float) $item->effective_cost_coeff)->toBe(0.5)
        ->and($item->modifiers->pluck('key')->sort()->values()->all())
        ->toBe(['chipped_edge', 'undercut']);
});

test('неизвестный ключ игнорируется, коэффициент остаётся базовым', function () {
    $item = customFlowReception(['no_such_rule']);

    expect((float) $item->effective_cost_coeff)->toBe(3.0)
        ->and($item->modifiers)->toBeEmpty();
});

test('ключ правила чужого отдела не применяется', function () {
    $other = H::departmentWithModifiers('Чужой отдел');
    app(DepartmentModifierService::class)->create($other, [
        'key'                => 'foreign_bonus',
        'name'               => 'Чужой бонус',
        'trigger'            => DepartmentModifier::TRIGGER_MANUAL,
        'applies_to'         => DepartmentModifier::SCOPE_BOTH,
        'worker_coeff_delta' => 5.0,
        'is_active'          => true,
    ]);

    $item = customFlowReception(['foreign_bonus']);

    expect((float) $item->effective_cost_coeff)->toBe(3.0);
});

test('выключенное правило не применяется, даже если ключ пришёл из формы', function () {
    $this->rule->update(['is_active' => false]);
    $this->dept->forgetSettingsCache();

    $item = customFlowReception(['chipped_edge']);

    expect((float) $item->effective_cost_coeff)->toBe(3.0);
});

test('пересчёт коэффициентов сохраняет своё правило', function () {
    $item      = customFlowReception(['chipped_edge']);
    $reception = $item->reception;

    $item->product->update(['prod_cost_coeff' => 5.0]);
    customFlowService()->refreshItemCoeffs($reception->fresh());

    // Новая база 5.0, правило −1.0 — выбор пользователя восстановлен из снапшота
    expect((float) $item->fresh()->effective_cost_coeff)->toBe(4.0)
        ->and($item->fresh()->modifiers->pluck('key')->all())->toBe(['chipped_edge']);
});
