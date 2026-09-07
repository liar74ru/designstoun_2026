<?php

use App\Models\Product;
use App\Models\ProductionItemModifier;
use App\Models\RawMaterialBatch;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Worker;
use App\Services\Moysklad\RawMaterialBatchSyncService;
use App\Services\Moysklad\StoneReceptionSyncService;
use App\Services\RawMaterialBatchService;
use App\Services\StoneReceptionService;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Снапшот правил на позиции: правка настроек отдела задним числом не должна
 * переписывать уже посчитанные зарплаты.
 */

beforeEach(function () {
    Cache::flush();
    Setting::updateOrCreate(['key' => 'PIECE_RATE'],       ['value' => '100']);
    Setting::updateOrCreate(['key' => 'UNDERCUT_PENALTY'], ['value' => '1.5']);
});

function snapshotService(): StoneReceptionService
{
    $sync = Mockery::mock(StoneReceptionSyncService::class);
    $sync->shouldReceive('syncReception')->andReturn(null);

    $batchSync = Mockery::mock(RawMaterialBatchSyncService::class);
    $batchSync->shouldReceive('syncCreated')->andReturn(null);
    $batchSync->shouldReceive('updateParentMove')->andReturn(null);

    return new StoneReceptionService($sync, app(RawMaterialBatchService::class), $batchSync);
}

function snapshotFixtures(): array
{
    $department = H::departmentWithModifiers();
    $rawProduct = Product::factory()->create(['sku' => '04-01']);
    $product    = Product::factory()->create(['sku' => '04-01-20', 'prod_cost_coeff' => 3.0]);
    $store      = Store::factory()->create();
    $cutter     = Worker::create(['name' => 'Пильщик', 'position' => 'Работник']);
    $receiver   = Worker::create(['name' => 'Приёмщик', 'position' => 'Мастер']);

    $batch = RawMaterialBatch::create([
        'product_id'         => $rawProduct->id,
        'initial_quantity'   => 100.0,
        'remaining_quantity' => 100.0,
        'current_store_id'   => $store->id,
        'current_worker_id'  => $cutter->id,
        'department_id'      => $department->id,
        'status'             => RawMaterialBatch::STATUS_IN_WORK,
    ]);

    return compact('department', 'product', 'store', 'cutter', 'receiver', 'batch');
}

function createReceptionWithUndercut(array $f): object
{
    return snapshotService()->create([
        'receiver_id'           => $f['receiver']->id,
        'cutter_id'             => $f['cutter']->id,
        'store_id'              => $f['store']->id,
        'raw_material_batch_id' => $f['batch']->id,
        'raw_quantity_used'     => 5.0,
        'products' => [
            ['product_id' => $f['product']->id, 'quantity' => 2.0, 'modifiers' => ['undercut']],
        ],
    ], false);
}

test('снапшот применённых правил пишется при создании позиции', function () {
    $f    = snapshotFixtures();
    $item = createReceptionWithUndercut($f)->items->first();

    $applied = ProductionItemModifier::where('stone_reception_item_id', $item->id)->get();

    expect($applied->pluck('key')->all())->toBe(['undercut'])
        ->and((float) $applied->first()->worker_coeff_delta)->toBe(-1.5)
        ->and($applied->first()->name)->toBe('Подкол > 80%');
});

test('sku-правило тоже попадает в снапшот', function () {
    $f = snapshotFixtures();
    $f['product']->update(['sku' => '04-07-20']);

    $item    = createReceptionWithUndercut($f)->items->first();
    $applied = ProductionItemModifier::where('stone_reception_item_id', $item->id)->get();

    // Порядок снапшота — порядок применения правил
    expect($applied->pluck('key')->all())->toBe(['mask_tile', 'undercut']);
});

test('правка правила задним числом не меняет ставку созданной позиции', function () {
    $f    = snapshotFixtures();
    $item = createReceptionWithUndercut($f)->items->first();

    $before = (float) $item->worker_cost_per_m2;

    // Админ ужесточает штраф за подкол
    $f['department']->modifiers()->where('key', 'undercut')->update(['worker_coeff_delta' => -5.0]);
    $f['department']->forgetSettingsCache();

    expect((float) $item->fresh()->worker_cost_per_m2)->toBe($before);
});

test('удаление правила не уносит историю позиции', function () {
    $f    = snapshotFixtures();
    $item = createReceptionWithUndercut($f)->items->first();

    $f['department']->modifiers()->where('key', 'undercut')->delete();

    $applied = ProductionItemModifier::where('stone_reception_item_id', $item->id)->first();

    expect($applied)->not->toBeNull()
        ->and($applied->key)->toBe('undercut')
        ->and($applied->department_modifier_id)->toBeNull()
        ->and((float) $applied->worker_coeff_delta)->toBe(-1.5);
});

test('снапшот заменяется целиком при пересчёте, дублей не остаётся', function () {
    $f         = snapshotFixtures();
    $reception = createReceptionWithUndercut($f);
    $item      = $reception->items->first();

    snapshotService()->refreshItemCoeffs($reception->fresh());

    expect(ProductionItemModifier::where('stone_reception_item_id', $item->id)->count())->toBe(1);
});

test('база позиции сохраняется отдельно от эффективного коэффициента', function () {
    $f    = snapshotFixtures();
    $item = createReceptionWithUndercut($f)->items->first();

    // prod_cost_coeff = 3.0, подкол −1.5 → effective 1.5, база остаётся 3.0
    expect((float) $item->base_cost_coeff)->toBe(3.0)
        ->and((float) $item->effective_cost_coeff)->toBe(1.5);
});
