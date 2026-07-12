<?php

use App\Models\Product;
use App\Models\Worker;
use Tests\Helpers\ReceptionTestHelper as H;

beforeEach(function () {
    $this->packer   = Worker::create(['name' => 'Упаковщик', 'position' => 'Мастер']);
    $this->receiver = Worker::create(['name' => 'Приёмщик',  'position' => 'Мастер']);
    $this->store    = H::store();

    $this->product        = Product::factory()->create(['sku' => '04-01-10']);
    $this->packageProduct = Product::factory()->create(['sku' => '07-03-01']);

    $this->basePayload = [
        'packer_id'          => $this->packer->id,
        'receiver_id'        => $this->receiver->id,
        'store_id'           => $this->store->id,
        'package_product_id' => $this->packageProduct->id,
        'package_quantity'   => 2.0,
        'products'           => [
            ['product_id' => $this->product->id, 'quantity' => 5.0],
        ],
    ];
});

describe('Валидация result_product_id при создании упаковки', function () {

    test('товар-результат не может совпадать с тарой', function () {
        $this->actingAs(H::adminUser())
            ->post(route('packagings.store'), $this->basePayload + [
                'result_product_id' => $this->packageProduct->id,
            ])
            ->assertSessionHasErrors('result_product_id');
    });

    test('товар-результат не может входить в упакованные продукты', function () {
        $this->actingAs(H::adminUser())
            ->post(route('packagings.store'), $this->basePayload + [
                'result_product_id' => $this->product->id,
            ])
            ->assertSessionHasErrors('result_product_id');
    });

    test('несуществующий товар-результат отклоняется', function () {
        $this->actingAs(H::adminUser())
            ->post(route('packagings.store'), $this->basePayload + [
                'result_product_id' => 999999,
            ])
            ->assertSessionHasErrors('result_product_id');
    });
});
