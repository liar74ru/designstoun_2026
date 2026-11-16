<?php

use App\Models\Product;
use App\Models\Setting;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\Worker;
use App\Services\StoneReceptionService;
use App\Services\WorkshopService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Правило применили к позиции, потом в отделе его выключили или поменяли.
 *
 * Снапшот позиции главнее настроек отдела: панель «Коэффициенты» и «Обновить коэф.»
 * сохраняют уже применённые правила с их числами. Привести позицию к текущим правилам —
 * «Обновить правила» (refreshItemRules); пока правила разошлись, кнопка видна.
 *
 * Плитка-маска (04-07-*) по умолчанию даёт пильщику +2.0, база продукта — 1.0.
 */
beforeEach(function () {
    Cache::flush();
    Setting::updateOrCreate(['key' => 'PIECE_RATE'], ['value' => '100']);

    Queue::fake();

    $this->admin      = H::adminUser();
    $this->department = H::departmentWithModifiers();
    $this->maskTile   = Product::factory()->create(['sku' => '04-07-20', 'prod_cost_coeff' => 1.0]);
});

/** Поменять правило отдела так, как это делает админка, и сбросить кэш правил. */
function changeRule($ctx, string $key, array $attributes): void
{
    $ctx->department->modifiers()->where('key', $key)->firstOrFail()->update($attributes);
    $ctx->department->forgetSettingsCache();
}

$documents = [
    'приёмка' => [
        'stone-receptions',
        StoneReceptionService::class,
        function ($ctx) {
            $store  = H::store();
            $cutter = H::cutter();
            $batch  = H::batch(H::product(), $store, $cutter, 50.0, ['department_id' => $ctx->department->id]);
            $item   = StoneReceptionItem::create([
                'stone_reception_id' => H::reception($batch, H::worker(), $cutter, $store, 5.0)->id,
                'product_id'         => $ctx->maskTile->id,
                'quantity'           => 2.0,
            ]);
            // Позиция с рассчитанной себестоимостью и снапшотом — как после создания приёмки
            app(StoneReceptionService::class)->refreshItemRules(StoneReception::find($item->stone_reception_id));

            return [$item->fresh(), StoneReception::find($item->stone_reception_id)];
        },
    ],
    'цех' => [
        'workshops',
        WorkshopService::class,
        function ($ctx) {
            $workshop = app(WorkshopService::class)->create([
                'packer_id'     => Worker::create(['name' => 'Работник', 'position' => 'Мастер'])->id,
                'receiver_id'   => Worker::create(['name' => 'Мастер', 'position' => 'Мастер'])->id,
                'store_id'      => Store::factory()->create()->id,
                'department_id' => $ctx->department->id,
                'raw_materials' => [
                    ['product_id' => Product::factory()->create(['sku' => '04-01-10'])->id, 'quantity' => 5.0],
                ],
                'products' => [
                    ['product_id' => $ctx->maskTile->id, 'quantity' => 1.0],
                ],
            ], false);

            return [$workshop->productItems()->first(), $workshop];
        },
    ],
];

foreach ($documents as $name => [$prefix, $serviceClass, $make]) {
    describe($name, function () use ($prefix, $serviceClass, $make) {

        /** Панель «Коэффициенты»: база 1.0 и заданные галочки. */
        $post = fn ($ctx, $document, $item, array $modifiers) => $ctx->actingAs($ctx->admin)
            ->post(route("{$prefix}.update-item-coeff", $document), ['items' => [[
                'item_id'           => $item->id,
                'base_coeff'        => 1.0,
                'master_base_coeff' => 0,
                'modifiers'         => $modifiers,
            ]]]);

        test('выключенное правило в панели остаётся со своими числами', function () use ($make, $post) {
            [$item, $document] = $make($this);
            changeRule($this, 'mask_tile', ['is_active' => false]);

            $post($this, $document, $item, ['mask_tile'])->assertRedirect();

            $item->refresh();
            expect((float) $item->effective_cost_coeff)->toBe(3.0)
                ->and($item->modifiers->pluck('key')->all())->toBe(['mask_tile']);
        });

        test('снятое выключенное правило уходит', function () use ($make, $post) {
            [$item, $document] = $make($this);
            changeRule($this, 'mask_tile', ['is_active' => false]);

            $post($this, $document, $item, [])->assertRedirect();

            $item->refresh();
            expect((float) $item->effective_cost_coeff)->toBe(1.0)
                ->and($item->modifiers)->toBeEmpty();
        });

        test('изменённое число правила в панели не подхватывается', function () use ($make, $post) {
            [$item, $document] = $make($this);
            changeRule($this, 'mask_tile', ['worker_coeff_delta' => 5.0]);

            $post($this, $document, $item, ['mask_tile'])->assertRedirect();

            expect((float) $item->fresh()->effective_cost_coeff)->toBe(3.0);
        });

        test('«Обновить коэф.» берёт базу из справочника и не трогает правила', function () use ($make, $serviceClass) {
            [$item, $document] = $make($this);
            changeRule($this, 'mask_tile', ['is_active' => false]);
            $this->maskTile->update(['prod_cost_coeff' => 1.5]);

            app($serviceClass)->refreshItemCoeffs($document);

            $item->refresh();
            expect((float) $item->base_cost_coeff)->toBe(1.5)
                ->and((float) $item->effective_cost_coeff)->toBe(3.5)
                ->and($item->modifiers->pluck('key')->all())->toBe(['mask_tile']);
        });

        test('правила совпадают с отделом — кнопки «Обновить правила» нет', function () use ($make, $serviceClass) {
            [, $document] = $make($this);

            expect(app($serviceClass)->hasOutdatedRules($document))->toBeFalse();
        });

        test('«Обновить правила» снимает выключенное правило', function () use ($make, $serviceClass) {
            [$item, $document] = $make($this);
            changeRule($this, 'mask_tile', ['is_active' => false]);

            expect(app($serviceClass)->hasOutdatedRules($document))->toBeTrue();

            app($serviceClass)->refreshItemRules($document);

            $item->refresh();
            expect((float) $item->effective_cost_coeff)->toBe(1.0)
                ->and($item->modifiers)->toBeEmpty()
                ->and(app($serviceClass)->hasOutdatedRules($document))->toBeFalse();
        });

        test('«Обновить правила» подхватывает новое число правила', function () use ($make, $serviceClass) {
            [$item, $document] = $make($this);
            changeRule($this, 'mask_tile', ['worker_coeff_delta' => 5.0]);

            expect(app($serviceClass)->hasOutdatedRules($document))->toBeTrue();

            app($serviceClass)->refreshItemRules($document);

            $item->refresh();
            expect((float) $item->effective_cost_coeff)->toBe(6.0)
                ->and((float) $item->modifiers->first()->worker_coeff_delta)->toBe(5.0);
        });

        test('«Обновить правила» добавляет новое sku-правило', function () use ($make, $serviceClass) {
            [$item, $document] = $make($this);
            $this->department->modifiers()->create([
                'key'                => 'green_bonus',
                'name'               => 'Зелёный сланец',
                'trigger'            => 'sku',
                'sku_pattern'        => '04-*-*',
                'applies_to'         => 'both',
                'worker_coeff_delta' => 0.5,
                'is_active'          => true,
            ]);
            $this->department->forgetSettingsCache();

            expect(app($serviceClass)->hasOutdatedRules($document))->toBeTrue();

            app($serviceClass)->refreshItemRules($document);

            $item->refresh();
            expect((float) $item->effective_cost_coeff)->toBe(3.5)
                ->and($item->modifiers->pluck('key')->sort()->values()->all())->toBe(['green_bonus', 'mask_tile']);
        });

        test('«Обновить правила» сохраняет ручной выбор и снятое sku-правило', function () use ($make, $post, $serviceClass) {
            [$item, $document] = $make($this);
            // В панели отмечен ручной «Подкол», sku-правило «Плитка-маска» снято
            $post($this, $document, $item, ['undercut'])->assertRedirect();
            changeRule($this, 'undercut', ['worker_coeff_delta' => -1.0]);

            expect(app($serviceClass)->hasOutdatedRules($document))->toBeTrue();

            app($serviceClass)->refreshItemRules($document);

            $item->refresh();
            expect($item->modifiers->pluck('key')->all())->toBe(['undercut'])
                ->and((float) $item->modifiers->first()->worker_coeff_delta)->toBe(-1.0)
                ->and((float) $item->effective_cost_coeff)->toBe(0.0)
                ->and($item->disabled_modifier_keys)->toBe(['mask_tile'])
                ->and(app($serviceClass)->hasOutdatedRules($document))->toBeFalse();
        });

        test('кнопка «Обновить правила» на странице — только когда правила разошлись', function () use ($make, $prefix) {
            [, $document] = $make($this);
            $show = fn () => $this->actingAs($this->admin)->get(route("{$prefix}.show", $document));

            $show()->assertOk()->assertDontSee(route("{$prefix}.refresh-item-rules", $document));

            changeRule($this, 'mask_tile', ['is_active' => false]);

            $show()->assertOk()
                ->assertSee(route("{$prefix}.refresh-item-rules", $document))
                ->assertSee('Обновить правила');
        });
    });
}
