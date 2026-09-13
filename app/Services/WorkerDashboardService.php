<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\RawMaterialMovement;
use App\Models\ReceptionLog;
use App\Models\ReceptionLogItem;
use App\Models\StoneReception;
use App\Models\WorkshopItem;
use App\Models\WorkshopLog;
use App\Models\WorkshopLogItem;
use App\Models\Worker;
use App\Support\DepartmentSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

// рефакторинг v2 от 26.04.2026 — controller → service
class WorkerDashboardService
{
    /**
     * Карты «product_id → позиция» по документам: сводка обращается к позиции
     * приёмки или цеха шесть раз на строку лога. Сбрасываются в начале запроса —
     * инстанс сервиса переживает его.
     *
     * @var array<string, Collection>
     */
    private array $itemMaps = [];

    public function getDefaultWeekRange(): array
    {
        $today = Carbon::today();

        $friday = $today->copy()->startOfDay();
        while ($friday->dayOfWeek !== Carbon::FRIDAY) {
            $friday->subDay();
        }

        $thursday = $friday->copy()->addDays(6)->endOfDay();

        return [$friday, $thursday];
    }

    public function getDashboardData(int $workerId, bool $isMaster, Carbon $dateFrom, Carbon $dateTo): array
    {
        $this->itemMaps = [];

        $workerField = $isMaster ? 'receiver_id' : 'cutter_id';

        $logs = ReceptionLog::with([
                'items.product',
                'stoneReception.store',
                'stoneReception.items.modifiers.modifier',
                'stoneReception.department',
                'stoneReception.rawMaterialBatch',
                'stoneReception.cutter',
                'rawMaterialBatch.product',
                $isMaster ? 'cutter' : 'receiver',
            ])
            ->where($workerField, $workerId)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->orderBy('created_at', 'desc')
            ->get();

        $logs->each(fn($log) => $log->items->each(fn($item) => $item->setRelation('receptionLog', $log)));

        // Производство цеха: работник — packer_id, мастер-приёмщик — receiver_id.
        $workshopWorkerField = $isMaster ? 'receiver_id' : 'packer_id';

        $workshopLogs = WorkshopLog::with([
                'items.product',
                'workshop.items.modifiers',
                'workshop.department',
                'workshop.packer',
            ])
            ->where($workshopWorkerField, $workerId)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->get();

        $workshopLogs->each(fn($log) => $log->items->each(fn($item) => $item->setRelation('workshopLog', $log)));

        $stoneReceptionIds = $logs->pluck('stone_reception_id')->filter()->unique();
        $stoneReceptions = StoneReception::with([
                'items.product',
                'items.modifiers.modifier',
                'rawMaterialBatch.product',
                $isMaster ? 'cutter' : 'receiver',
                'store',
            ])
            ->whereIn('id', $stoneReceptionIds)
            ->orderBy('created_at', 'desc')
            ->get();

        $batchIds = $stoneReceptions->pluck('raw_material_batch_id')->filter()->unique();
        $rawBatches = RawMaterialBatch::with(['product', 'currentStore'])
            ->where(function ($q) use ($workerId, $batchIds, $isMaster) {
                $q->whereIn('id', $batchIds);
                if (!$isMaster) {
                    $q->orWhere(function ($q2) use ($workerId) {
                        $q2->whereIn('status', [RawMaterialBatch::STATUS_NEW, RawMaterialBatch::STATUS_IN_WORK])
                            ->where('current_worker_id', $workerId);
                    });
                }
            })
            ->orderByRaw("CASE status WHEN 'in_work' THEN 0 WHEN 'new' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->get();

        $summary        = $this->mergeProductSummaries(
            $this->buildProductSummary($logs),
            $this->buildWorkshopProductSummary($workshopLogs),
        );
        $totalPay       = $isMaster ? null : $summary->sum('pay');
        $totalMasterPay = $isMaster ? $summary->sum('masterPay') : null;
        // Мастер принимает в нескольких отделах — сводка выводится отдельной таблицей на отдел.
        // Ставки настраиваются per-department, поэтому показываются внутри таблицы отдела.
        $summaryByDepartment = $isMaster
            ? $this->buildSummaryByDepartment($logs, $workshopLogs)
            : null;

        // Ставка пильщика настраивается per-department: работнику показываем ставку
        // его основного отдела, а не глобальную. У мастера ставки выводятся
        // внутри таблицы каждого отдела (см. buildSummaryByDepartment).
        $pieceRate = $isMaster
            ? null
            : DepartmentSettings::pieceRate(Worker::find($workerId)?->department_id);

        return compact(
            'logs',
            'stoneReceptions',
            'rawBatches',
            'summary',
            'summaryByDepartment',
            'totalPay',
            'totalMasterPay',
            'pieceRate',
        );
    }

    /**
     * Группировка логов приёмок и цеха по эффективному отделу документа
     * (свой → отдел партии/упаковщика → отдел пильщика): на каждый отдел —
     * сводка по продуктам и итоги. Отсортировано по имени отдела.
     *
     * @param  callable|null  $filterSummary  доп. фильтр строк сводки внутри отдела
     */
    private function buildSummaryByDepartment(
        Collection $logs,
        Collection $workshopLogs,
        ?callable $filterSummary = null
    ): Collection {
        // Ключ «отдел не определён» — 0: groupBy превращает null в пустую строку,
        // а она не пройдёт в DepartmentSettings::*(?int).
        $stoneByDept    = $logs->groupBy(fn($log) => $log->stoneReception?->effectiveDepartmentId() ?? 0);
        $workshopByDept = $workshopLogs->groupBy(fn($log) => $log->workshop?->effectiveDepartmentId() ?? 0);

        // Отдел может прийти от партии или работника, поэтому связь department
        // самого документа не годится — берём отделы одним запросом.
        $departments = Department::whereIn(
            'id',
            $stoneByDept->keys()->merge($workshopByDept->keys())->filter()->unique()
        )->get()->keyBy('id');

        return $stoneByDept->keys()
            ->merge($workshopByDept->keys())
            ->unique()
            ->map(function ($deptId) use ($stoneByDept, $workshopByDept, $filterSummary, $departments) {
                $deptStoneLogs    = $stoneByDept->get($deptId, collect());
                $deptWorkshopLogs = $workshopByDept->get($deptId, collect());
                $deptId           = ((int) $deptId) ?: null;

                $summary = $this->mergeProductSummaries(
                    $this->buildProductSummary($deptStoneLogs),
                    $this->buildWorkshopProductSummary($deptWorkshopLogs),
                );

                if ($filterSummary) {
                    $summary = $summary->filter($filterSummary)->values();
                }

                return [
                    'department'     => $deptId ? $departments->get($deptId) : null,
                    'summary'        => $summary,
                    'totalQuantity'  => $summary->sum('quantity'),
                    'totalPay'       => $summary->sum('pay'),
                    'totalMasterPay' => $summary->sum('masterPay'),
                    // Надбавки больше не рубли, а коэффициенты-правила отдела:
                    // показывать MASTER_UNDERCUT_RATE стало бы враньём. Перечень
                    // действующих правил появится здесь вместе с их отображением.
                    'rates'          => [
                        'piece' => DepartmentSettings::pieceRate($deptId),
                        'base'  => DepartmentSettings::masterBaseRate($deptId),
                    ],
                ];
            })
            ->filter(fn($row) => $row['summary']->isNotEmpty())
            ->sortBy(fn($row) => $row['department']?->name ?? "\u{FFFF}")
            ->values();
    }

    /**
     * Общий дашборд предприятия: агрегация всего производства за период по всем приёмкам,
     * с группировкой по отделам (внутри — сводка по продуктам).
     *
     * Отдел документа берётся эффективный (свой → отдел партии/упаковщика → отдел
     * работника): у документов, созданных админом, и у исторических записей
     * колонка department_id бывает пустой, но отдел из связей известен.
     */
    public function getEnterpriseDashboardData(
        ?Carbon $dateFrom,
        ?Carbon $dateTo,
        array $departmentIds = [],
        $rawProductId = null,
        $productId = null,
        ?array $restrictDepartmentIds = null
    ): array {
        $this->itemMaps = [];

        // Явный фильтр отделов из формы — строго выбранные отделы (у не-админа —
        // пересечение с доступными). Без фильтра не-админ видит свои отделы плюс
        // документы, отдел которых не определяется вообще; админ — всё.
        $includeUndetermined = empty($departmentIds);

        if ($departmentIds) {
            $scopeIds = $restrictDepartmentIds === null
                ? array_values($departmentIds)
                : array_values(array_intersect($departmentIds, $restrictDepartmentIds));
            $scopeIds = $scopeIds ?: [-1];
        } else {
            // null — без ограничения (админ); [] — работник без отдела (только «без отдела»).
            $scopeIds = $restrictDepartmentIds === null ? null : ($restrictDepartmentIds ?: [-1]);
        }

        $logs = ReceptionLog::with([
                'items.product',
                'stoneReception.items.modifiers',
                'stoneReception.department',
                'stoneReception.rawMaterialBatch',
                'stoneReception.cutter',
            ])
            ->when($dateFrom && $dateTo, fn ($q) => $q->whereBetween('created_at', [$dateFrom, $dateTo]))
            ->when($scopeIds !== null, fn ($q) => $q->whereHas('stoneReception',
                fn ($q2) => $q2->inEffectiveDepartments($scopeIds, $includeUndetermined)))
            ->when($rawProductId, fn ($q) => $q->whereHas('rawMaterialBatch',
                fn ($q2) => $q2->where('product_id', $rawProductId)))
            ->orderBy('created_at', 'desc')
            ->get();

        // Привязать inverse-отношение log↔items, чтобы buildProductSummary не делал N+1.
        $logs->each(fn($log) => $log->items->each(fn($item) => $item->setRelation('receptionLog', $log)));

        // Производство цеха: логи по тому же паттерну, что и ReceptionLog.
        // Статус цеха не фильтруем — паритет с приёмками камня.
        $workshopLogs = WorkshopLog::with([
                'items.product',
                'workshop.items.modifiers',
                'workshop.department',
                'workshop.packer',
            ])
            ->when($dateFrom && $dateTo, fn ($q) => $q->whereBetween('created_at', [$dateFrom, $dateTo]))
            ->when($scopeIds !== null, fn ($q) => $q->whereHas('workshop',
                fn ($q2) => $q2->inEffectiveDepartments($scopeIds, $includeUndetermined)))
            ->when($rawProductId, fn ($q) => $q->whereHas('workshop.items',
                fn ($q2) => $q2->where('role', WorkshopItem::ROLE_RAW)->where('product_id', $rawProductId)))
            ->get();

        $workshopLogs->each(fn($log) => $log->items->each(fn($item) => $item->setRelation('workshopLog', $log)));

        // Фильтр по продукту (плитке): оставляем только строки выбранного товара.
        $departments = $this->buildSummaryByDepartment(
            $logs,
            $workshopLogs,
            $productId ? fn($row) => $row['product']?->id == $productId : null,
        );

        // Вкладка «Сырьё»: движения сырья не связаны с плиткой — фильтр $productId не применяется.
        $incomingRaw = $this->buildIncomingRawSummary(
            $dateFrom, $dateTo, $scopeIds, $rawProductId, $includeUndetermined
        );

        return [
            'departments'       => $departments,
            'grandQuantity'     => $departments->sum('totalQuantity'),
            'grandPay'          => $departments->sum('totalPay'),
            'grandMasterPay'    => $departments->sum('totalMasterPay'),
            'incomingRaw'       => $incomingRaw,
            'incomingRawTotal'  => $incomingRaw->sum('quantity'),
            'filterDepartments' => $restrictDepartmentIds !== null
                ? Department::whereIn('id', $restrictDepartmentIds)->orderBy('name')->get()
                : Department::orderBy('name')->get(),
            'filterRawProducts' => Product::whereIn('id',
                    RawMaterialBatch::query()->distinct()->pluck('product_id'))
                ->orderBy('name')->get(),
            'filterProducts'    => Product::whereIn('id',
                    ReceptionLogItem::query()->distinct()->pluck('product_id')
                        ->merge(WorkshopLogItem::where('role', WorkshopItem::ROLE_PRODUCT)
                            ->distinct()->pluck('product_id')))
                ->orderBy('name')->get(),
            'dateFrom'          => $dateFrom,
            'dateTo'            => $dateTo,
        ];
    }

    /**
     * Входящее сырьё за период: первичные поступления (движения 'create'),
     * сгруппированные по продукту (камню). Только 'create', чтобы не задваивать
     * объём дочерними партиями от передач/разделения.
     */
    private function buildIncomingRawSummary(
        ?Carbon $dateFrom,
        ?Carbon $dateTo,
        ?array $departmentIds = null,
        $rawProductId = null,
        bool $includeUndetermined = false
    ): Collection {
        return RawMaterialMovement::query()
            ->where('movement_type', 'create')
            ->when($dateFrom && $dateTo, fn ($q) => $q->whereBetween('created_at', [$dateFrom, $dateTo]))
            ->when($departmentIds !== null, fn ($q) => $q->where(
                function ($q2) use ($departmentIds, $includeUndetermined) {
                    $q2->whereHas('batch',
                        fn ($q3) => $q3->inEffectiveDepartments($departmentIds, $includeUndetermined));

                    if ($includeUndetermined) {
                        $q2->orWhereDoesntHave('batch'); // движение без партии — тоже «без отдела»
                    }
                }
            ))
            ->when($rawProductId, fn ($q) => $q->whereHas('batch',
                fn ($q2) => $q2->where('product_id', $rawProductId)))
            ->with('batch.product')
            ->get()
            ->groupBy(fn ($m) => $m->batch?->product_id)
            ->map(function ($movements) {
                $product = $movements->first()?->batch?->product;

                return [
                    'product'  => $product,
                    'uom'      => $product?->uom ?: 'м³',
                    'quantity' => $movements->sum(fn ($m) => (float) $m->quantity),
                ];
            })
            ->filter(fn ($row) => $row['quantity'] > 0)
            ->sortByDesc('quantity')
            ->values();
    }

    /**
     * Подпись набора правил позиции: отсортированные ключи снапшота.
     *
     * Сортировка обязательна — порядок строк в production_item_modifiers от
     * расчёта не зависит, а ключ группировки обязан быть стабильным, иначе
     * одинаковые наборы дадут разные строки сводки.
     */
    private function modifierSignature(?Model $item): string
    {
        return $item ? $item->modifiers->pluck('key')->sort()->values()->implode('|') : '';
    }

    /**
     * Позиция приёмки под строку лога.
     *
     * Связь восстанавливается по product_id: у ReceptionLogItem ссылки на
     * позицию нет. Если один товар заведён в приёмке двумя позициями с разными
     * наборами правил, вся выработка по нему считается по первой — ограничение
     * существует с появлением логов и снимается только FK на позицию.
     */
    private function receptionItemFor($logItem): ?Model
    {
        $reception = $logItem->receptionLog?->stoneReception;

        if (!$reception) {
            return null;
        }

        $this->itemMaps['r' . $reception->id] ??= $reception->items->keyBy('product_id');

        return $this->itemMaps['r' . $reception->id]->get($logItem->product_id);
    }

    /** Позиция цеха под строку лога. Оговорка та же, что у receptionItemFor(). */
    private function workshopItemFor($logItem): ?Model
    {
        $workshop = $logItem->workshopLog?->workshop;

        if (!$workshop) {
            return null;
        }

        $this->itemMaps['w' . $workshop->id] ??= $workshop->items
            ->where('role', WorkshopItem::ROLE_PRODUCT)
            ->keyBy('product_id');

        return $this->itemMaps['w' . $workshop->id]->get($logItem->product_id);
    }

    private function buildProductSummary(Collection $logs): Collection
    {
        $allItems = $logs->flatMap(fn($log) => $log->items);

        return $allItems
            ->groupBy(fn($logItem) => $logItem->product_id
                . '#' . $this->modifierSignature($this->receptionItemFor($logItem)))
            ->map(function ($items) {
                $firstLogItem       = $items->first();
                $product            = $firstLogItem->product;
                $firstReceptionItem = $this->receptionItemFor($firstLogItem);

                $quantity = $items->sum(fn($item) => (float) $item->quantity_delta);

                $pay = $items->sum(function ($logItem) use ($product) {
                    $delta = (float) $logItem->quantity_delta;
                    if (abs($delta) < 0.0001) return 0.0;

                    $receptionItem = $this->receptionItemFor($logItem);

                    if ($receptionItem) {
                        return $delta * $receptionItem->effectiveProdCost();
                    }

                    return $product
                        ? $product->calculateWorkerPay(
                            $delta,
                            $logItem->receptionLog?->stoneReception?->effectiveDepartmentId()
                        )
                        : 0.0;
                });

                $effCoeffDisplay = $items
                    ->map(fn($li) => $this->receptionItemFor($li)?->effective_cost_coeff)
                    ->filter()
                    ->avg() ?? $product?->prod_cost_coeff ?? 0;

                $masterPay = $items->sum(function ($logItem) {
                    $delta = (float) $logItem->quantity_delta;
                    if (abs($delta) < 0.0001) return 0.0;
                    $receptionItem = $this->receptionItemFor($logItem);
                    return $receptionItem ? $delta * (float) ($receptionItem->master_cost_per_m2 ?? 0) : 0.0;
                });

                return [
                    'product'       => $product,
                    'quantity'      => $quantity,
                    'coeff'         => $effCoeffDisplay,
                    'modifiers'     => $firstReceptionItem?->modifiers ?? collect(),
                    'signature'     => $this->modifierSignature($firstReceptionItem),
                    'prodCost'      => $items
                        ->map(fn($li) => $this->receptionItemFor($li)?->worker_cost_per_m2)
                        ->filter()
                        ->avg() ?? $product?->prodCost(
                            $effCoeffDisplay,
                            $firstLogItem->receptionLog?->stoneReception?->effectiveDepartmentId()
                        ) ?? 0,
                    'masterCost'    => $items
                        ->map(fn($li) => $this->receptionItemFor($li)?->master_cost_per_m2)
                        ->filter()
                        ->avg() ?? 0,
                    'pay'           => $pay,
                    'masterPay'     => $masterPay,
                ];
            })
            ->filter(fn($row) => abs($row['quantity']) > 0.0001)
            ->sortBy(fn($row) => ($row['product']?->sku ?? '') . '#' . $row['signature'])
            ->values();
    }

    /**
     * Сводка производства цеха по продуктам: аналог buildProductSummary,
     * но по дельтам WorkshopLogItem (role=product); стоимости и правила —
     * из родительских позиций Workshop.items (role=product).
     */
    private function buildWorkshopProductSummary(Collection $logs): Collection
    {
        $allItems = $logs->flatMap(fn($log) => $log->items->where('role', WorkshopItem::ROLE_PRODUCT));

        return $allItems
            ->groupBy(fn($logItem) => $logItem->product_id
                . '#' . $this->modifierSignature($this->workshopItemFor($logItem)))
            ->map(function ($items) {
                $firstLogItem = $items->first();
                $product      = $firstLogItem->product;
                $firstWsItem  = $this->workshopItemFor($firstLogItem);

                $quantity = $items->sum(fn($item) => (float) $item->quantity_delta);

                $pay = $items->sum(function ($logItem) use ($product) {
                    $delta = (float) $logItem->quantity_delta;
                    if (abs($delta) < 0.0001) return 0.0;

                    $wsItem = $this->workshopItemFor($logItem);
                    if ($wsItem) {
                        return $delta * $wsItem->effectiveProdCost();
                    }

                    return $product
                        ? $product->calculateWorkerPay(
                            $delta,
                            $logItem->workshopLog?->workshop?->effectiveDepartmentId()
                        )
                        : 0.0;
                });

                $effCoeffDisplay = $items
                    ->map(fn($li) => $this->workshopItemFor($li)?->effective_cost_coeff)
                    ->filter()
                    ->avg() ?? $product?->prod_cost_coeff ?? 0;

                $masterPay = $items->sum(function ($logItem) {
                    $delta = (float) $logItem->quantity_delta;
                    if (abs($delta) < 0.0001) return 0.0;
                    $wsItem = $this->workshopItemFor($logItem);
                    return $wsItem ? $delta * (float) ($wsItem->master_cost_per_m2 ?? 0) : 0.0;
                });

                return [
                    'product'       => $product,
                    'quantity'      => $quantity,
                    'coeff'         => $effCoeffDisplay,
                    'modifiers'     => $firstWsItem?->modifiers ?? collect(),
                    'signature'     => $this->modifierSignature($firstWsItem),
                    'prodCost'      => $items
                        ->map(fn($li) => $this->workshopItemFor($li)?->worker_cost_per_m2)
                        ->filter()
                        ->avg() ?? $product?->prodCost(
                            $effCoeffDisplay,
                            $firstLogItem->workshopLog?->workshop?->effectiveDepartmentId()
                        ) ?? 0,
                    'masterCost'    => $items
                        ->map(fn($li) => $this->workshopItemFor($li)?->master_cost_per_m2)
                        ->filter()
                        ->avg() ?? 0,
                    'pay'           => $pay,
                    'masterPay'     => $masterPay,
                ];
            })
            ->filter(fn($row) => abs($row['quantity']) > 0.0001)
            ->values();
    }

    /**
     * Слияние двух сводок по продуктам: строки с одним товаром и одинаковым
     * набором правил объединяются, суммы складываются.
     */
    private function mergeProductSummaries(Collection $a, Collection $b): Collection
    {
        return $a->concat($b)
            ->groupBy(fn($row) => ($row['product']?->id ?? 0) . '#' . $row['signature'])
            ->map(function ($rows) {
                if ($rows->count() === 1) {
                    return $rows->first();
                }

                $quantity = $rows->sum('quantity');
                $wavg = fn(string $key) => abs($quantity) > 0.0001
                    ? $rows->sum(fn($r) => $r[$key] * $r['quantity']) / $quantity
                    : $rows->avg($key);

                return [
                    'product'       => $rows->first()['product'],
                    'quantity'      => $quantity,
                    'coeff'         => $wavg('coeff'),
                    // Набор правил у сливаемых строк одинаков по построению ключа.
                    'modifiers'     => $rows->first()['modifiers'],
                    'signature'     => $rows->first()['signature'],
                    'prodCost'      => $wavg('prodCost'),
                    'masterCost'    => $wavg('masterCost'),
                    'pay'           => $rows->sum('pay'),
                    'masterPay'     => $rows->sum('masterPay'),
                ];
            })
            ->filter(fn($row) => abs($row['quantity']) > 0.0001)
            ->sortBy(fn($row) => ($row['product']?->sku ?? '') . '#' . $row['signature'])
            ->values();
    }
}
