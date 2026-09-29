<?php

use App\Models\Counterparty;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\SupplierOrder;
use App\Models\Worker;
use App\Models\Workshop;
use App\Support\DepartmentAccess;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AccessTestHelper as Access;
use Tests\Helpers\ReceptionTestHelper as H;

/*
 * Отдел новых и переносимых записей. UI показывает не-админу только его отделы,
 * сервер проверяет то же самое: создать запись в чужом отделе или без отдела,
 * перенести свою в чужой отдел, оформить приёмку на чужую партию — нельзя.
 * Старые записи без отдела правятся как раньше.
 */

beforeEach(fn () => Http::fake());

describe('DepartmentAccess::canAssign', function () {

    test('админ — любой отдел и без отдела, мастер — только свой', function () {
        $own     = Access::department('Свой');
        $foreign = Access::department('Чужой');
        $master  = Access::master($own, 'stone-receptions');
        $admin   = H::adminUser();

        expect(DepartmentAccess::canAssign($admin, $foreign->id))->toBeTrue()
            ->and(DepartmentAccess::canAssign($admin, null))->toBeTrue()
            ->and(DepartmentAccess::canAssign($master, $own->id))->toBeTrue()
            ->and(DepartmentAccess::canAssign($master, (string) $own->id))->toBeTrue()
            ->and(DepartmentAccess::canAssign($master, $foreign->id))->toBeFalse()
            ->and(DepartmentAccess::canAssign($master, null))->toBeFalse();
    });
});

describe('Приёмка', function () {

    test('на партию чужого отдела приёмку не оформить', function () {
        $own     = Access::department('Свой');
        $foreign = Access::department('Чужой');
        $batch   = H::batch(H::product(), H::store(), H::cutter(), 10.0, ['department_id' => $foreign->id]);

        $this->actingAs(Access::master($own, 'stone-receptions'))
            ->post(route('stone-receptions.store'), H::receptionPostData(
                H::worker(), $batch->currentWorker, $batch->currentStore, $batch, 2.0,
            ) + ['department_id' => $own->id])
            ->assertSessionHasErrors('raw_material_batch_id');

        expect(StoneReception::count())->toBe(0);
        expect((float) $batch->fresh()->remaining_quantity)->toBe(10.0);
        expect($batch->fresh()->department_id)->toBe($foreign->id);
    });

    test('в чужом отделе приёмку не создать', function () {
        $own     = Access::department('Свой');
        $foreign = Access::department('Чужой');
        $batch   = H::batch(H::product(), H::store(), H::cutter(), 10.0, ['department_id' => $own->id]);

        $this->actingAs(Access::master($own, 'stone-receptions'))
            ->post(route('stone-receptions.store'), H::receptionPostData(
                H::worker(), $batch->currentWorker, $batch->currentStore, $batch, 2.0,
            ) + ['department_id' => $foreign->id])
            ->assertSessionHasErrors(['department_id' => 'Можно выбрать только свой отдел.']);

        expect(StoneReception::count())->toBe(0);
    });

    test('при правке приёмку не перенести на чужую партию', function () {
        $own      = Access::department('Свой');
        $foreign  = Access::department('Чужой');
        $store    = H::store();
        $cutter   = H::cutter();
        $receiver = H::worker();
        $product  = H::product();
        $ownBatch = H::batch(H::product(), $store, $cutter, 10.0, ['department_id' => $own->id]);
        $alien    = H::batch(H::product(), $store, $cutter, 10.0, ['department_id' => $foreign->id]);
        $reception = H::reception($ownBatch, $receiver, $cutter, $store, 2.0, ['department_id' => $own->id]);
        $reception->items()->create(['product_id' => $product->id, 'quantity' => 1.0]);

        $this->actingAs(Access::master($own, 'stone-receptions'))
            ->put(route('stone-receptions.update', $reception), [
                'receiver_id'           => $receiver->id,
                'cutter_id'             => $cutter->id,
                'store_id'              => $store->id,
                'raw_material_batch_id' => $alien->id,
                'raw_quantity_delta'    => 0,
                'products'              => [['product_id' => $product->id, 'quantity' => 1.0]],
            ])
            ->assertSessionHasErrors('raw_material_batch_id');

        expect($reception->fresh()->raw_material_batch_id)->toBe($ownBatch->id);
        expect((float) $alien->fresh()->remaining_quantity)->toBe(10.0);
    });
});

describe('Партия сырья', function () {

    test('в чужом отделе партию не создать', function () {
        $own     = Access::department('Свой');
        $foreign = Access::department('Чужой');

        $this->actingAs(Access::master($own, 'raw-batches'))
            ->post(route('raw-batches.store'), [
                'product_id'         => H::product()->id,
                'quantity'           => 5.0,
                'worker_id'          => H::cutter()->id,
                'from_store_id'      => H::store('Источник')->id,
                'to_store_id'        => H::store('Пильщик')->id,
                'department_id'      => $foreign->id,
                'ignore_stock_check' => 1,
            ])
            ->assertSessionHasErrors(['department_id' => 'Можно выбрать только свой отдел.']);

        expect(RawMaterialBatch::count())->toBe(0);
    });
});

describe('Цех', function () {

    function daWorkshopPayload(array $overrides = []): array
    {
        $store = H::store();

        return array_merge([
            'packer_id'        => H::worker('Упаковщик', 'Работник')->id,
            'receiver_id'      => H::worker('Приёмщик')->id,
            'store_id'         => $store->id,
            'product_store_id' => $store->id,
            'raw_materials'    => [['product_id' => H::product()->id, 'quantity' => 1.0]],
            'products'         => [['product_id' => H::product()->id, 'quantity' => 1.0]],
        ], $overrides);
    }

    test('в чужом отделе операцию не создать', function () {
        $own     = Access::department('Свой');
        $foreign = Access::department('Чужой');

        $this->actingAs(Access::master($own, 'workshops'))
            ->post(route('workshops.store'), daWorkshopPayload(['department_id' => $foreign->id]))
            ->assertSessionHasErrors(['department_id' => 'Можно выбрать только свой отдел.']);

        expect(Workshop::count())->toBe(0);
    });

    test('свою операцию в чужой отдел не перенести', function () {
        $own      = Access::department('Свой');
        $foreign  = Access::department('Чужой');
        $payload  = daWorkshopPayload();
        $workshop = Workshop::create([
            'packer_id'     => $payload['packer_id'],
            'receiver_id'   => $payload['receiver_id'],
            'store_id'      => $payload['store_id'],
            'status'        => Workshop::STATUS_ACTIVE,
            'department_id' => $own->id,
        ]);

        $this->actingAs(Access::master($own, 'workshops'))
            ->put(route('workshops.update', $workshop), $payload + ['department_id' => $foreign->id])
            ->assertSessionHasErrors(['department_id' => 'Можно выбрать только свой отдел.']);

        expect($workshop->fresh()->department_id)->toBe($own->id);
    });

    test('старую операцию без отдела мастер правит, не назначая отдел', function () {
        $own      = Access::department('Свой');
        $payload  = daWorkshopPayload(['notes' => 'поправлено']);
        $workshop = Workshop::create([
            'packer_id'   => $payload['packer_id'],
            'receiver_id' => $payload['receiver_id'],
            'store_id'    => $payload['store_id'],
            'status'      => Workshop::STATUS_ACTIVE,
        ]);

        $this->actingAs(Access::master($own, 'workshops'))
            ->put(route('workshops.update', $workshop), $payload)
            ->assertSessionHasNoErrors();

        expect($workshop->fresh()->notes)->toBe('поправлено');
        expect($workshop->fresh()->department_id)->toBeNull();
    });
});

describe('Поступление сырья', function () {

    function daSupplierPayload(?Worker $receiver): array
    {
        return [
            'store_id'        => H::store()->id,
            'counterparty_id' => Counterparty::create(['name' => 'Поставщик', 'moysklad_id' => uniqid()])->id,
            'receiver_id'     => $receiver?->id,
            'number'          => 'П-' . uniqid(),
            'products'        => [['product_id' => H::product()->id, 'quantity' => 1.0]],
        ];
    }

    test('с приёмщиком чужого отдела поступление не создать', function () {
        $own     = Access::department('Свой');
        $foreign = Access::department('Чужой');
        $alien   = Worker::create(['name' => 'Чужой приёмщик', 'position' => 'Мастер', 'department_id' => $foreign->id]);

        $this->actingAs(Access::master($own, 'supplier-orders'))
            ->post(route('supplier-orders.store'), daSupplierPayload($alien))
            ->assertSessionHasErrors(['receiver_id' => 'Приёмщик из другого отдела — выберите приёмщика своего отдела.']);

        expect(SupplierOrder::count())->toBe(0);
    });

    test('без отдела поступление не создать', function () {
        $own = Access::department('Свой');

        $this->actingAs(Access::master($own, 'supplier-orders'))
            ->post(route('supplier-orders.store'), daSupplierPayload(null))
            ->assertSessionHasErrors('receiver_id');

        expect(SupplierOrder::count())->toBe(0);
    });

    test('старое поступление без отдела мастер правит, не назначая отдел', function () {
        $own     = Access::department('Свой');
        $payload = daSupplierPayload(null);
        $order   = SupplierOrder::create([
            'number'          => 'П-старое',
            'store_id'        => $payload['store_id'],
            'counterparty_id' => $payload['counterparty_id'],
            'status'          => SupplierOrder::STATUS_NEW,
        ]);

        $this->actingAs(Access::master($own, 'supplier-orders'))
            ->put(route('supplier-orders.update', $order), array_merge($payload, ['number' => 'П-поправлено']))
            ->assertSessionHasNoErrors();

        expect($order->fresh()->number)->toBe('П-поправлено');
        expect($order->fresh()->department_id)->toBeNull();
    });
});

describe('Работник', function () {

    test('мастер не создаёт работника без отдела', function () {
        $own = Access::department('Свой');

        $this->actingAs(Access::master($own, 'workers'))
            ->post(route('workers.store'), ['name' => 'Без отдела', 'position' => 'Работник'])
            ->assertSessionHasErrors(['department_ids' => 'Выберите хотя бы один свой отдел.']);

        expect(Worker::where('name', 'Без отдела')->exists())->toBeFalse();
    });

    test('мастер не снимает с работника последний отдел', function () {
        $own    = Access::department('Свой');
        $worker = Worker::create(['name' => 'Пильщик', 'position' => 'Работник', 'department_id' => $own->id]);

        $this->actingAs(Access::master($own, 'workers'))
            ->put(route('workers.update', $worker), ['name' => 'Пильщик', 'position' => 'Работник'])
            ->assertSessionHasErrors('department_ids');

        expect($worker->fresh()->departmentIds())->toBe([$own->id]);
    });

    test('админ по-прежнему создаёт работника без отдела', function () {
        $this->actingAs(H::adminUser())
            ->post(route('workers.store'), ['name' => 'Без отдела', 'position' => 'Работник'])
            ->assertSessionHasNoErrors();

        expect(Worker::where('name', 'Без отдела')->exists())->toBeTrue();
    });
});
