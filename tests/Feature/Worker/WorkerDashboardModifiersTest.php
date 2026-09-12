<?php

use App\Models\Product;
use App\Models\ProductionItemModifier;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Store;
use App\Models\Worker;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Models\WorkshopLog;
use App\Models\WorkshopLogItem;
use App\Services\WorkerDashboardService;
use Carbon\Carbon;
use Tests\Helpers\ReceptionTestHelper as H;

/**
 * Сводка дашборда группируется по набору применённых правил.
 *
 * Раньше ключом были два булевых флага, поэтому своё правило отдела не
 * отделяло позицию от обычной, а мелкая плитка в ключ не входила вовсе.
 */

beforeEach(function () {
    $this->dept   = H::departmentWithModifiers();
    $this->cutter = Worker::create([
        'name'          => 'Пильщик Сводки',
        'position'      => 'Работник',
        'department_id' => $this->dept->id,
    ]);
    $this->receiver = Worker::create(['name' => 'Мастер Сводки', 'position' => 'Мастер']);
});

/**
 * Приёмка с одной позицией и заданным набором правил в снапшоте.
 * Снапшот пишется напрямую: тест проверяет сводку, а не расчёт себестоимости.
 *
 * @param array<int, string> $ruleKeys ключи правил в порядке записи
 */
function dashboardReceptionWithRules(Product $tile, float $qty, array $ruleKeys): StoneReception
{
    $reception = StoneReception::create([
        'receiver_id'   => test()->receiver->id,
        'cutter_id'     => test()->cutter->id,
        'store_id'      => Store::factory()->create()->id,
        'department_id' => test()->dept->id,
        'status'        => 'active',
    ]);

    $item = StoneReceptionItem::create([
        'stone_reception_id' => $reception->id,
        'product_id'         => $tile->id,
        'quantity'           => $qty,
        'worker_cost_per_m2' => 100,
        'master_cost_per_m2' => 50,
    ]);

    foreach ($ruleKeys as $key) {
        $rule = test()->dept->modifiers()->where('key', $key)->first();
        ProductionItemModifier::create(array_merge(
            ProductionItemModifier::attributesFrom($rule),
            ['stone_reception_item_id' => $item->id],
        ));
    }

    $log = ReceptionLog::create([
        'stone_reception_id' => $reception->id,
        'cutter_id'          => test()->cutter->id,
        'receiver_id'        => test()->receiver->id,
        'type'               => ReceptionLog::TYPE_CREATED,
        'raw_quantity_delta' => 0,
    ]);

    ReceptionLogItem::create([
        'reception_log_id' => $log->id,
        'product_id'       => $tile->id,
        'quantity_delta'   => $qty,
    ]);

    return $reception;
}

/** Операция цеха с одной позицией продукта и набором правил в снапшоте. */
function dashboardWorkshopWithRules(Product $tile, float $qty, array $ruleKeys): void
{
    $workshop = Workshop::create([
        'packer_id'     => test()->cutter->id,
        'receiver_id'   => test()->receiver->id,
        'store_id'      => Store::factory()->create()->id,
        'department_id' => test()->dept->id,
        'status'        => Workshop::STATUS_ACTIVE,
    ]);

    WorkshopItem::create([
        'workshop_id' => $workshop->id,
        'product_id'  => Product::factory()->create()->id,
        'role'        => WorkshopItem::ROLE_RAW,
        'quantity'    => 1.0,
    ]);

    $item = WorkshopItem::create([
        'workshop_id'        => $workshop->id,
        'product_id'         => $tile->id,
        'role'               => WorkshopItem::ROLE_PRODUCT,
        'quantity'           => $qty,
        'worker_cost_per_m2' => 100,
        'master_cost_per_m2' => 50,
    ]);

    foreach ($ruleKeys as $key) {
        $rule = test()->dept->modifiers()->where('key', $key)->first();
        ProductionItemModifier::create(array_merge(
            ProductionItemModifier::attributesFrom($rule),
            ['workshop_item_id' => $item->id],
        ));
    }

    $log = WorkshopLog::create([
        'workshop_id'            => $workshop->id,
        'packer_id'              => test()->cutter->id,
        'receiver_id'            => test()->receiver->id,
        'type'                   => WorkshopLog::TYPE_CREATED,
        'package_quantity_delta' => 0,
    ]);

    WorkshopLogItem::create([
        'workshop_log_id' => $log->id,
        'product_id'      => $tile->id,
        'role'            => WorkshopItem::ROLE_PRODUCT,
        'quantity_delta'  => $qty,
    ]);
}

function dashboardSummary(): Illuminate\Support\Collection
{
    return app(WorkerDashboardService::class)->getDashboardData(
        test()->cutter->id,
        false,
        Carbon::now()->subDay()->startOfDay(),
        Carbon::now()->addDay()->endOfDay(),
    )['summary'];
}

test('один товар с разными наборами правил даёт разные строки', function () {
    $tile = Product::factory()->create(['prod_cost_coeff' => 2.5]);

    dashboardReceptionWithRules($tile, 10.0, ['undercut']);
    dashboardReceptionWithRules($tile, 4.0, []);

    $summary = dashboardSummary();

    expect($summary)->toHaveCount(2);

    $withRule = $summary->firstWhere('signature', 'undercut');
    $plain    = $summary->firstWhere('signature', '');

    expect((float) $withRule['quantity'])->toEqual(10.0)
        ->and($withRule['modifiers']->pluck('key')->all())->toBe(['undercut'])
        ->and((float) $plain['quantity'])->toEqual(4.0)
        ->and($plain['modifiers'])->toBeEmpty();
});

test('порядок правил в снапшоте на группировку не влияет', function () {
    $tile = Product::factory()->create(['prod_cost_coeff' => 2.5]);

    dashboardReceptionWithRules($tile, 3.0, ['undercut', 'edging']);
    dashboardReceptionWithRules($tile, 2.0, ['edging', 'undercut']);

    $summary = dashboardSummary();

    expect($summary)->toHaveCount(1)
        ->and((float) $summary->first()['quantity'])->toEqual(5.0)
        ->and($summary->first()['signature'])->toBe('edging|undercut');
});

test('приёмка и цех с одинаковым набором правил сливаются в одну строку', function () {
    $tile = Product::factory()->create(['prod_cost_coeff' => 2.5]);

    dashboardReceptionWithRules($tile, 10.0, ['undercut']);
    dashboardWorkshopWithRules($tile, 5.0, ['undercut']);

    $summary = dashboardSummary();

    expect($summary)->toHaveCount(1)
        ->and((float) $summary->first()['quantity'])->toEqual(15.0)
        ->and($summary->first()['modifiers']->pluck('key')->all())->toBe(['undercut']);
});

test('приёмка и цех с разными наборами правил остаются разными строками', function () {
    $tile = Product::factory()->create(['prod_cost_coeff' => 2.5]);

    dashboardReceptionWithRules($tile, 10.0, ['undercut']);
    dashboardWorkshopWithRules($tile, 5.0, []);

    expect(dashboardSummary())->toHaveCount(2);
});

test('строка сводки несёт имя, цвет и иконку правила — их рисует партиал меток', function () {
    $tile = Product::factory()->create(['prod_cost_coeff' => 2.5]);

    dashboardReceptionWithRules($tile, 10.0, ['undercut']);

    $rule = dashboardSummary()->first()['modifiers']->first();

    expect($rule->name)->toBe('Подкол > 80%')
        ->and($rule->color)->toBe('#FFC107')
        ->and($rule->icon)->toBe('bi-lightning-charge-fill');
});
