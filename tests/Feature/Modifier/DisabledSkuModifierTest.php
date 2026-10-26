<?php

use App\Models\Product;
use App\Models\Setting;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\Worker;
use App\Models\Workshop;
use App\Services\Moysklad\StoneReceptionSyncService;
use App\Services\Moysklad\WorkshopSyncService;
use App\Services\StoneReceptionService;
use App\Services\WorkshopService;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Снятие sku-правила у позиции в правке коэффициентов.
 *
 * Плитка-маска (04-07-*) срабатывает по SKU и даёт пильщику +2.0. В панели
 * «Коэффициенты» её галочку можно снять: неотмеченное сработавшее правило —
 * снятое, оно хранится в disabled_modifier_keys и переживает обновление
 * из справочника.
 */

beforeEach(function () {
    Cache::flush();
    Setting::updateOrCreate(['key' => 'PIECE_RATE'], ['value' => '100']);

    $this->mock(StoneReceptionSyncService::class, fn ($m) => $m->shouldReceive('syncReception')->andReturn(null));
    $this->mock(WorkshopSyncService::class, fn ($m) => $m->shouldReceive('syncWorkshop')->andReturn(null));

    $this->admin      = H::adminUser();
    $this->department = H::departmentWithModifiers();
    $this->maskTile   = Product::factory()->create(['sku' => '04-07-20', 'prod_cost_coeff' => 1.0]);
});

/** Приёмка отдела с правилами и одной позицией плитки-маски. */
function maskTileReception($ctx): StoneReceptionItem
{
    $store    = H::store();
    $cutter   = H::cutter();
    $batch    = H::batch(H::product(), $store, $cutter, 50.0, ['department_id' => $ctx->department->id]);
    $reception = H::reception($batch, H::worker(), $cutter, $store, 5.0);

    return StoneReceptionItem::create([
        'stone_reception_id' => $reception->id,
        'product_id'         => $ctx->maskTile->id,
        'quantity'           => 2.0,
    ]);
}

/** Строка продукта цеха отдела с правилами — плитка-маска. */
function maskTileWorkshopItem($ctx)
{
    $store = Store::factory()->create();

    $workshop = app(WorkshopService::class)->create([
        'packer_id'     => Worker::create(['name' => 'Работник', 'position' => 'Мастер'])->id,
        'receiver_id'   => Worker::create(['name' => 'Мастер', 'position' => 'Мастер'])->id,
        'store_id'      => $store->id,
        'department_id' => $ctx->department->id,
        'raw_materials' => [
            ['product_id' => Product::factory()->create(['sku' => '04-01-10'])->id, 'quantity' => 5.0],
        ],
        'products' => [
            ['product_id' => $ctx->maskTile->id, 'quantity' => 1.0],
        ],
    ], false);

    return $workshop->productItems()->first();
}

/** Отправить панель «Коэффициенты» с базой 1.0 и заданными галочками. */
function postItemCoeff($ctx, string $route, $document, $item, array $modifiers)
{
    return $ctx->actingAs($ctx->admin)->post(route($route, $document), [
        'items' => [[
            'item_id'           => $item->id,
            'base_coeff'        => 1.0,
            'master_base_coeff' => 0,
            'modifiers'         => $modifiers,
        ]],
    ]);
}

describe('приёмка', function () {

    test('отмеченное sku-правило применяется', function () {
        $item = maskTileReception($this);

        postItemCoeff($this, 'stone-receptions.update-item-coeff', $item->reception, $item, ['mask_tile'])
            ->assertRedirect();

        $item->refresh();
        expect((float) $item->effective_cost_coeff)->toBe(3.0)
            ->and($item->modifiers->pluck('key')->all())->toBe(['mask_tile'])
            ->and($item->disabled_modifier_keys)->toBeNull();
    });

    test('снятое sku-правило не применяется и запоминается', function () {
        $item = maskTileReception($this);

        postItemCoeff($this, 'stone-receptions.update-item-coeff', $item->reception, $item, [])
            ->assertRedirect();

        $item->refresh();
        expect((float) $item->effective_cost_coeff)->toBe(1.0)
            ->and($item->modifiers)->toBeEmpty()
            ->and($item->disabled_modifier_keys)->toBe(['mask_tile']);
    });

    test('снятие переживает обновление из справочника', function () {
        $item = maskTileReception($this);
        postItemCoeff($this, 'stone-receptions.update-item-coeff', $item->reception, $item, []);

        app(StoneReceptionService::class)->refreshItemCoeffs(StoneReception::find($item->stone_reception_id));

        $item->refresh();
        expect((float) $item->effective_cost_coeff)->toBe(1.0)
            ->and($item->modifiers)->toBeEmpty()
            ->and($item->disabled_modifier_keys)->toBe(['mask_tile']);
    });

    test('повторно отмеченное правило снова применяется', function () {
        $item = maskTileReception($this);
        postItemCoeff($this, 'stone-receptions.update-item-coeff', $item->reception, $item, []);
        postItemCoeff($this, 'stone-receptions.update-item-coeff', $item->reception, $item, ['mask_tile']);

        $item->refresh();
        expect((float) $item->effective_cost_coeff)->toBe(3.0)
            ->and($item->disabled_modifier_keys)->toBeNull();
    });

    test('ручное правило отмечается рядом со снятым sku-правилом', function () {
        $item = maskTileReception($this);

        postItemCoeff($this, 'stone-receptions.update-item-coeff', $item->reception, $item, ['undercut']);

        $item->refresh();
        // База 1.0 + подкол −1.5, плитка-маска снята
        expect((float) $item->effective_cost_coeff)->toBe(-0.5)
            ->and($item->modifiers->pluck('key')->all())->toBe(['undercut'])
            ->and($item->disabled_modifier_keys)->toBe(['mask_tile']);
    });
});

describe('цех', function () {

    test('sku-правило по умолчанию применяется к новой строке', function () {
        $item = maskTileWorkshopItem($this);

        expect((float) $item->effective_cost_coeff)->toBe(3.0)
            ->and($item->disabled_modifier_keys)->toBeNull();
    });

    test('снятое sku-правило не применяется и запоминается', function () {
        $item = maskTileWorkshopItem($this);

        postItemCoeff($this, 'workshops.update-item-coeff', $item->workshop, $item, [])
            ->assertRedirect();

        $item->refresh();
        expect((float) $item->effective_cost_coeff)->toBe(1.0)
            ->and($item->modifiers)->toBeEmpty()
            ->and($item->disabled_modifier_keys)->toBe(['mask_tile']);
    });

    test('снятие переживает обновление из справочника', function () {
        $item = maskTileWorkshopItem($this);
        postItemCoeff($this, 'workshops.update-item-coeff', $item->workshop, $item, []);

        app(WorkshopService::class)->refreshItemCoeffs(Workshop::find($item->workshop_id));

        $item->refresh();
        expect((float) $item->effective_cost_coeff)->toBe(1.0)
            ->and($item->modifiers)->toBeEmpty();
    });

    test('повторно отмеченное правило снова применяется', function () {
        $item = maskTileWorkshopItem($this);
        postItemCoeff($this, 'workshops.update-item-coeff', $item->workshop, $item, []);
        postItemCoeff($this, 'workshops.update-item-coeff', $item->workshop, $item, ['mask_tile']);

        $item->refresh();
        expect((float) $item->effective_cost_coeff)->toBe(3.0)
            ->and($item->disabled_modifier_keys)->toBeNull();
    });
});
