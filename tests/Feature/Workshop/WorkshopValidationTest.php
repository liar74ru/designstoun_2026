<?php

use App\Models\Department;
use App\Models\Product;
use App\Models\Worker;
use App\Services\WorkshopService;
use Tests\Helpers\ReceptionTestHelper as H;

beforeEach(function () {
    $this->packer   = Worker::create(['name' => 'Упаковщик', 'position' => 'Мастер']);
    $this->receiver = Worker::create(['name' => 'Приёмщик',  'position' => 'Мастер']);
    $this->store    = H::store();

    $this->product        = Product::factory()->create(['sku' => '04-01-10']);
    $this->packageProduct = Product::factory()->create(['sku' => '07-03-01']);
    $this->outProduct     = Product::factory()->create(['sku' => '05-01-01']);

    $this->basePayload = [
        'packer_id'     => $this->packer->id,
        'receiver_id'   => $this->receiver->id,
        'store_id'      => $this->store->id,
        'raw_materials' => [
            ['product_id' => $this->product->id, 'quantity' => 5.0],
        ],
        'packages' => [
            ['product_id' => $this->packageProduct->id, 'quantity' => 2.0],
        ],
        'products' => [
            ['product_id' => $this->outProduct->id, 'quantity' => 1.0],
        ],
    ];
});

describe('Валидация складов при создании операции', function () {

    test('без product_store_id запрос отклоняется', function () {
        $this->actingAs(H::adminUser())
            ->post(route('workshops.store'), $this->basePayload)
            ->assertSessionHasErrors('product_store_id');
    });

    test('несуществующий склад продукта отклоняется', function () {
        $this->actingAs(H::adminUser())
            ->post(route('workshops.store'), $this->basePayload + [
                'product_store_id' => 'non-existent-store-id',
            ])
            ->assertSessionHasErrors('product_store_id');
    });

    test('с валидным product_store_id ошибки валидации складов нет', function () {
        $this->actingAs(H::adminUser())
            ->post(route('workshops.store'), $this->basePayload + [
                'product_store_id' => $this->store->id,
            ])
            ->assertSessionDoesntHaveErrors(['store_id', 'product_store_id']);
    });
});

describe('Валидация блоков сырья/продукта', function () {

    test('без сырья запрос отклоняется', function () {
        $payload = $this->basePayload + ['product_store_id' => $this->store->id];
        unset($payload['raw_materials']);

        $this->actingAs(H::adminUser())
            ->post(route('workshops.store'), $payload)
            ->assertSessionHasErrors('raw_materials');
    });

    test('без продукта на выходе запрос отклоняется', function () {
        $payload = $this->basePayload + ['product_store_id' => $this->store->id];
        unset($payload['products']);

        $this->actingAs(H::adminUser())
            ->post(route('workshops.store'), $payload)
            ->assertSessionHasErrors('products');
    });

    test('операция без упаковки проходит валидацию', function () {
        $payload = $this->basePayload + ['product_store_id' => $this->store->id];
        $payload['packages'] = [];

        $this->actingAs(H::adminUser())
            ->post(route('workshops.store'), $payload)
            ->assertSessionDoesntHaveErrors(['packages', 'raw_materials', 'products']);
    });

    test('отрицательные затраты отклоняются', function () {
        $this->actingAs(H::adminUser())
            ->post(route('workshops.store'), $this->basePayload + [
                'product_store_id'      => $this->store->id,
                'manual_processing_sum' => -5,
            ])
            ->assertSessionHasErrors('manual_processing_sum');
    });
});

describe('Сбой при сохранении', function () {

    beforeEach(function () {
        $this->validPayload = $this->basePayload + [
            'product_store_id' => $this->store->id,
            'department_id'    => Department::create(['name' => 'Упаковка'])->id,
        ];
    });

    test('создание: подробности только в логе, пользователю общий текст', function () {
        $this->partialMock(WorkshopService::class, fn ($mock) => $mock
            ->shouldReceive('create')->andThrow(new \RuntimeException('SQLSTATE секретная деталь')));

        $this->actingAs(H::adminUser())
            ->post(route('workshops.store'), $this->validPayload)
            ->assertSessionHasErrors(['error' => 'Не удалось сохранить операцию цеха. Попробуйте ещё раз, если повторится — сообщите администратору.']);
    });

    test('правка: подробности только в логе, пользователю общий текст', function () {
        $workshop = app(WorkshopService::class)->create($this->validPayload, false);

        $this->partialMock(WorkshopService::class, fn ($mock) => $mock
            ->shouldReceive('update')->andThrow(new \RuntimeException('SQLSTATE секретная деталь')));

        $this->actingAs(H::adminUser())
            ->put(route('workshops.update', $workshop), $this->validPayload)
            ->assertSessionHasErrors(['error' => 'Не удалось сохранить операцию цеха. Попробуйте ещё раз, если повторится — сообщите администратору.']);
    });
});
