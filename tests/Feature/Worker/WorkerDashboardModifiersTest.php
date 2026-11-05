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
use Illuminate\Support\Collection;
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
function dashboardWorkshopWithRules(Product $tile, float $qty, array $ruleKeys): Workshop
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

    return $workshop;
}

/** Приёмка с заданными ставками: выработка +qty или −qty по цене именно этого документа. */
function dashboardReceptionAtCost(Product $tile, float $qty, float $worker, float $master = 0): StoneReception
{
    $reception = dashboardReceptionWithRules($tile, $qty, []);
    $reception->items()->update(['worker_cost_per_m2' => $worker, 'master_cost_per_m2' => $master]);

    return $reception;
}

function dashboardWorkshopAtCost(Product $tile, float $qty, float $worker, float $master = 0): Workshop
{
    $workshop = dashboardWorkshopWithRules($tile, $qty, []);
    $workshop->items()->where('role', WorkshopItem::ROLE_PRODUCT)
        ->update(['worker_cost_per_m2' => $worker, 'master_cost_per_m2' => $master]);

    return $workshop;
}

function dashboardData(?Carbon $from = null, ?Carbon $to = null, ?int $workerId = null): array
{
    return app(WorkerDashboardService::class)->getDashboardData(
        $workerId ?? test()->cutter->id,
        $from ?? Carbon::now()->subDay()->startOfDay(),
        $to ?? Carbon::now()->addDay()->endOfDay(),
    );
}

function dashboardSummary(): Collection
{
    return app(WorkerDashboardService::class)->getDashboardData(
        test()->cutter->id,
        Carbon::now()->subDay()->startOfDay(),
        Carbon::now()->addDay()->endOfDay(),
    )['departments']->first()['worker']['summary'];
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

describe('Нулевое количество при ненулевой сумме', function () {

    test('приёмки по разным ставкам: строка остаётся, сумма входит в итог', function () {
        $tile = Product::factory()->create();

        dashboardReceptionAtCost($tile, 10.0, 520);
        dashboardReceptionAtCost($tile, -10.0, 400);

        $data = dashboardData();
        $row  = $data['departments']->first()['worker']['summary']->sole();

        expect((float) $row['quantity'])->toEqual(0.0)
            ->and((float) $row['pay'])->toEqual(1200.0)
            ->and((float) $data['totalPay'])->toEqual(1200.0);
    });

    test('цех по разным ставкам: то же', function () {
        $tile = Product::factory()->create();

        dashboardWorkshopAtCost($tile, 10.0, 520);
        dashboardWorkshopAtCost($tile, -10.0, 400);

        expect((float) dashboardData()['totalPay'])->toEqual(1200.0);
    });

    test('сумма мастера тоже не теряется', function () {
        $tile = Product::factory()->create();

        dashboardReceptionAtCost($tile, 10.0, 0, 150);
        dashboardReceptionAtCost($tile, -10.0, 0, 100);

        $data = dashboardData(workerId: $this->receiver->id);

        expect((float) $data['totalMasterPay'])->toEqual(500.0);
    });

    test('приёмка и цех сливаются в ноль: строка остаётся', function () {
        $tile = Product::factory()->create();

        dashboardReceptionAtCost($tile, 10.0, 520);
        dashboardWorkshopAtCost($tile, -10.0, 400);

        $row = dashboardSummary()->sole();

        expect((float) $row['quantity'])->toEqual(0.0)
            ->and((float) $row['pay'])->toEqual(1200.0);
    });

    test('правка по той же ставке: пустой строки нет', function () {
        $tile = Product::factory()->create();

        dashboardReceptionAtCost($tile, 10.0, 520);
        dashboardReceptionAtCost($tile, -10.0, 520);

        $data = dashboardData();

        expect($data['departments'])->toBeEmpty()
            ->and((float) $data['totalPay'])->toEqual(0.0);
    });

    test('шум чисел с плавающей точкой пустую строку не создаёт', function () {
        $tile = Product::factory()->create();

        dashboardReceptionAtCost($tile, 0.1, 100);
        dashboardReceptionAtCost($tile, 0.2, 100);
        dashboardReceptionAtCost($tile, -0.3, 100);

        expect(dashboardData()['departments'])->toBeEmpty();
    });

    test('начисление по неделям равно начислению за весь диапазон: перерасчёт не ложный', function () {
        $tile   = Product::factory()->create();
        $monday = Carbon::now()->subWeeks(2)->startOfWeek();

        $reception = dashboardReceptionAtCost($tile, 10.0, 520);
        ReceptionLog::where('stone_reception_id', $reception->id)->update(['created_at' => $monday->copy()->addDay()]);

        $correction = dashboardReceptionAtCost($tile, -10.0, 400);
        ReceptionLog::where('stone_reception_id', $correction->id)->update(['created_at' => $monday->copy()->addWeek()->addDay()]);

        $first  = dashboardData($monday, $monday->copy()->endOfWeek())['totalPay'];
        $second = dashboardData($monday->copy()->addWeek(), $monday->copy()->addWeek()->endOfWeek())['totalPay'];
        $whole  = dashboardData($monday, $monday->copy()->addWeek()->endOfWeek())['totalPay'];

        expect((float) $first)->toEqual(5200.0)
            ->and((float) $second)->toEqual(-4000.0)
            ->and((float) $whole)->toEqual(1200.0);
    });
});
