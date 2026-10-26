<?php

use App\Models\Product;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Фильтры списка цеха: сырьё ищется по строкам сырья, продукт — по строкам
 * продукта на выходе, упаковка — по строкам тары. Один товар в разных ролях
 * не должен находиться чужим фильтром.
 */

beforeEach(function () {
    $this->store  = H::store();
    $this->packer = Worker::create(['name' => 'Работник', 'position' => 'Мастер']);

    $this->stone   = Product::factory()->create(['sku' => '04-01-10']);
    $this->tile    = Product::factory()->create(['sku' => '05-01-01']);
    $this->package = Product::factory()->create(['sku' => '07-03-01']);
});

/** Операция цеха со строками [роль => товар]. */
function filteredWorkshop($ctx, array $rows): Workshop
{
    $workshop = Workshop::create([
        'packer_id'   => $ctx->packer->id,
        'receiver_id' => $ctx->packer->id,
        'store_id'    => $ctx->store->id,
        'status'      => Workshop::STATUS_ACTIVE,
    ]);

    foreach ($rows as $role => $product) {
        WorkshopItem::create([
            'workshop_id' => $workshop->id,
            'product_id'  => $product->id,
            'role'        => $role,
            'quantity'    => 1.0,
        ]);
    }

    return $workshop;
}

/** Id операций, найденных списком с данными фильтрами. */
function filteredWorkshopIds($ctx, array $filter): array
{
    $ids = null;

    $ctx->actingAs(H::adminUser())
        ->get(route('workshops.index', ['filter' => $filter]))
        ->assertOk()
        ->assertViewHas('workshops', function ($workshops) use (&$ids) {
            $ids = $workshops->pluck('id')->sort()->values()->all();

            return true;
        });

    return $ids;
}

test('фильтр по сырью находит операцию по строке сырья, а не по продукту', function () {
    // Один и тот же товар: в первой операции — сырьё, во второй — продукт на выходе
    $asRaw     = filteredWorkshop($this, [WorkshopItem::ROLE_RAW => $this->stone, WorkshopItem::ROLE_PRODUCT => $this->tile]);
    $asProduct = filteredWorkshop($this, [WorkshopItem::ROLE_RAW => $this->tile, WorkshopItem::ROLE_PRODUCT => $this->stone]);

    expect(filteredWorkshopIds($this, ['raw_product_id' => $this->stone->id]))->toBe([$asRaw->id])
        ->and(filteredWorkshopIds($this, ['raw_product_id' => $this->tile->id]))->toBe([$asProduct->id]);
});

test('фильтр по продукту находит операцию по продукту на выходе, а не по сырью', function () {
    $asRaw     = filteredWorkshop($this, [WorkshopItem::ROLE_RAW => $this->stone, WorkshopItem::ROLE_PRODUCT => $this->tile]);
    $asProduct = filteredWorkshop($this, [WorkshopItem::ROLE_RAW => $this->tile, WorkshopItem::ROLE_PRODUCT => $this->stone]);

    expect(filteredWorkshopIds($this, ['product_id' => $this->stone->id]))->toBe([$asProduct->id])
        ->and(filteredWorkshopIds($this, ['product_id' => $this->tile->id]))->toBe([$asRaw->id]);
});

test('фильтр по типу упаковки находит операцию по строке тары', function () {
    $packed   = filteredWorkshop($this, [WorkshopItem::ROLE_RAW => $this->stone, WorkshopItem::ROLE_PACKAGE => $this->package]);
    filteredWorkshop($this, [WorkshopItem::ROLE_RAW => $this->stone, WorkshopItem::ROLE_PRODUCT => $this->tile]);

    expect(filteredWorkshopIds($this, ['package_product_id' => $this->package->id]))->toBe([$packed->id]);
});

test('сырьё и продукт сочетаются', function () {
    $match = filteredWorkshop($this, [WorkshopItem::ROLE_RAW => $this->stone, WorkshopItem::ROLE_PRODUCT => $this->tile]);
    filteredWorkshop($this, [WorkshopItem::ROLE_RAW => $this->stone, WorkshopItem::ROLE_PRODUCT => $this->package]);

    expect(filteredWorkshopIds($this, [
        'raw_product_id' => $this->stone->id,
        'product_id'     => $this->tile->id,
    ]))->toBe([$match->id]);
});

test('в списках фильтра только товары своей роли', function () {
    filteredWorkshop($this, [
        WorkshopItem::ROLE_RAW     => $this->stone,
        WorkshopItem::ROLE_PRODUCT => $this->tile,
        WorkshopItem::ROLE_PACKAGE => $this->package,
    ]);

    $this->actingAs(H::adminUser())
        ->get(route('workshops.index'))
        ->assertOk()
        ->assertViewHas('filterRawProducts', fn ($list) => $list->pluck('id')->all() === [$this->stone->id])
        ->assertViewHas('filterProducts', fn ($list) => $list->pluck('id')->all() === [$this->tile->id])
        ->assertViewHas('filterPackageProducts', fn ($list) => $list->pluck('id')->all() === [$this->package->id]);
});
