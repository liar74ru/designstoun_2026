<?php

use App\Models\Product;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use Tests\Helpers\ReceptionTestHelper as H;

function makeWorkshopForCopy(): Workshop
{
    $store    = H::store();
    $packer   = H::worker('Упаковщиков Павел');
    $receiver = H::worker('Приёмщиков Иван');

    $workshop = Workshop::create([
        'packer_id'   => $packer->id,
        'receiver_id' => $receiver->id,
        'store_id'    => $store->id,
        'status'      => Workshop::STATUS_ACTIVE,
    ]);

    foreach ([
        WorkshopItem::ROLE_RAW     => 'Сырьё для копии',
        WorkshopItem::ROLE_PRODUCT => 'Продукт для копии',
        WorkshopItem::ROLE_PACKAGE => 'Тара для копии',
    ] as $role => $name) {
        WorkshopItem::create([
            'workshop_id' => $workshop->id,
            'product_id'  => Product::factory()->create(['name' => $name])->id,
            'role'        => $role,
            'quantity'    => 1.0,
        ]);
    }

    return $workshop;
}

// ══════════════════════════════════════════════════════════════════════════════
// copy() → create?copy_from=
// ══════════════════════════════════════════════════════════════════════════════

describe('WorkshopController copy()', function () {

    test('редиректит на create с copy_from и packer_id', function () {
        $workshop = makeWorkshopForCopy();

        $this->actingAs(H::adminUser())
            ->post(route('workshops.copy', $workshop))
            ->assertRedirect(route('workshops.create', [
                'copy_from' => $workshop->id,
                'packer_id' => $workshop->packer_id,
            ]));
    });

    test('форма создания с copy_from подставляет позиции исходной операции', function () {
        $workshop = makeWorkshopForCopy();

        $copyItems = $this->actingAs(H::adminUser())
            ->get(route('workshops.create', ['copy_from' => $workshop->id]))
            ->assertOk()
            ->viewData('copyItems');

        expect(collect($copyItems)->pluck('product_label', 'role')->all())->toBe([
            WorkshopItem::ROLE_RAW     => 'Сырьё для копии',
            WorkshopItem::ROLE_PRODUCT => 'Продукт для копии',
            WorkshopItem::ROLE_PACKAGE => 'Тара для копии',
        ]);
    });

    test('неверный copy_from — пустая форма, а не ошибка', function (string $copyFrom) {
        $this->actingAs(H::adminUser())
            ->get(route('workshops.create', ['copy_from' => $copyFrom]))
            ->assertOk()
            ->assertViewHas('copyItems', []);
    })->with([
        'несуществующий' => ['999999'],
        'текст'          => ['abc'],
    ]);
});
