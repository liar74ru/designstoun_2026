<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\RawMaterialBatch;
use App\Models\StoneReception;
use App\Models\ReceptionLog;
use App\Models\StoneReceptionItem;
use App\Models\Worker;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkerDashboardController extends Controller
{
    /**
     * Определяем границы "рабочей недели" (пятница–четверг).
     *
     * Логика:
     *   Сегодня пятница или позже — неделя началась сегодня (в ближайшую прошлую пятницу).
     *   Сегодня до пятницы — берём пятницу прошлой недели.
     *
     * Carbon::FRIDAY = 5
     */
    private function getDefaultWeekRange(): array
    {
        $today = Carbon::today();

        // dayOfWeek: 0=вс, 1=пн, 2=вт, 3=ср, 4=чт, 5=пт, 6=сб
        // Находим ближайшую прошедшую (или сегодняшнюю) пятницу
        $friday = $today->copy()->startOfDay();
        while ($friday->dayOfWeek !== Carbon::FRIDAY) {
            $friday->subDay();
        }

        // Четверг = пятница + 6 дней, конец дня
        $thursday = $friday->copy()->addDays(6)->endOfDay();

        return [$friday, $thursday];
    }

    /**
     * Страница пильщика: его выработка и зарплата за период.
     * Доступна самому работнику и администратору.
     */
    public function show(Request $request, ?int $workerId = null)
    {
        $user = Auth::user();

        // Определяем, чьи данные показываем:
        // - администратор может смотреть любого работника (через параметр URL)
        // - мастер с отделом может смотреть работников своего отдела
        // - обычный работник видит только себя
        if ($user->isAdmin() && $workerId) {
            $worker = Worker::findOrFail($workerId);
        } elseif ($user->isMaster() && $workerId) {
            $worker = Worker::findOrFail($workerId);
            $masterDeptId = $user->worker?->department_id;
            if ($masterDeptId && $worker->department_id !== $masterDeptId) {
                abort(403, 'Нет доступа к дашборду этого работника');
            }
        } else {
            // Для не-мастера и не-администратора — только свой профиль
            abort_unless($user->worker_id, 403, 'Ваш аккаунт не привязан к работнику');
            $worker = $user->worker;
        }

        // Парсим период из запроса или берём текущую неделю по умолчанию
        [$defaultFrom, $defaultTo] = $this->getDefaultWeekRange();

        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->input('date_from'))->startOfDay()
            : $defaultFrom;

        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->input('date_to'))->endOfDay()
            : $defaultTo;

        // Загружаем логи приёмок пильщика за период из reception_logs
        // Это точная история: первичные приёмки + все дельты редактирований
        $logs = ReceptionLog::with([
                'items.product',
                'stoneReception.store',
                'stoneReception.items',   // нужны для effective_cost_coeff
                'rawMaterialBatch.product',
                'receiver',
            ])
            ->where('cutter_id', $worker->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->orderBy('created_at', 'desc')
            ->get();

        // Для обратной совместимости с шаблоном — $receptions теперь это логи
        $receptions = $logs;

        // Приёмки за период (для вида «По партиям»)
        $stoneReceptions = StoneReception::with([
                'items.product',
                'rawMaterialBatch.product',
                'receiver',
                'store',
            ])
            ->where('cutter_id', $worker->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->orderBy('created_at', 'desc')
            ->get();

        // Партии сырья: активные у пильщика + задействованные в периоде
        $rawBatches = RawMaterialBatch::with(['product', 'currentStore'])
            ->where(function ($q) use ($worker, $dateFrom, $dateTo) {
                $q->where(function ($q2) use ($worker) {
                    $q2->whereIn('status', [RawMaterialBatch::STATUS_NEW, RawMaterialBatch::STATUS_IN_WORK])
                        ->where('current_worker_id', $worker->id);
                })->orWhereHas('receptions', function ($q2) use ($worker, $dateFrom, $dateTo) {
                    $q2->where('cutter_id', $worker->id)
                        ->whereBetween('created_at', [$dateFrom, $dateTo]);
                });
            })
            ->orderByRaw("CASE status WHEN 'in_work' THEN 0 WHEN 'new' THEN 1 ELSE 2 END")
            ->orderByDesc('updated_at')
            ->get();

        // Считаем сводку по продуктам за период на основе дельт лога
        $summary = $this->buildProductSummary($logs);

        // Итоговая зарплата = сумма по всем продуктам
        $totalPay = $summary->sum('pay');

        return view('workers.dashboard.show', compact(
            'worker',
            'receptions',
            'stoneReceptions',
            'rawBatches',
            'summary',
            'totalPay',
            'dateFrom',
            'dateTo',
        ));
    }

    /**
     * Строим сводку: сколько какого продукта произведено и сколько заработано.
     *
     * ВАЖНО: для расчёта зарплаты используем effective_cost_coeff, зафиксированный
     * в позиции приёмки на момент её создания, а не текущий коэффициент продукта.
     * Это гарантирует что изменения в справочнике не пересчитывают прошлые выплаты.
     */
    private function buildProductSummary($logs)
    {
        // Собираем все позиции всех логов в один плоский список.
        // quantity_delta может быть отрицательной (корректировка вниз) — это нормально.
        $allItems = $logs->flatMap(fn($log) => $log->items);

        // Группируем по составному ключу product_id + is_undercut,
        // чтобы один продукт с разными флагами is_undercut попадал в разные строки.
        return $allItems
            ->groupBy(function ($logItem) {
                $receptionItem = $logItem->receptionLog?->stoneReception?->items
                    ->firstWhere('product_id', $logItem->product_id);
                $isUndercut = $receptionItem ? (bool) $receptionItem->is_undercut : false;
                return $logItem->product_id . '_' . ($isUndercut ? '1' : '0');
            })
            ->map(function ($items) {
                $firstLogItem  = $items->first();
                $product       = $firstLogItem->product;
                $firstReceptionItem = $firstLogItem->receptionLog?->stoneReception?->items
                    ->firstWhere('product_id', $firstLogItem->product_id);
                $isUndercut = $firstReceptionItem ? (bool) $firstReceptionItem->is_undercut : false;

                // Суммируем дельты — итог и есть фактически произведённое количество
                $quantity = $items->sum(fn($item) => (float) $item->quantity_delta);

                // Считаем зарплату через effective_cost_coeff каждой позиции лога.
                // Каждый log_item несёт дельту; для него коэффициент берём из
                // соответствующей позиции приёмки (stone_reception_item).
                $pay = $items->sum(function ($logItem) use ($product) {
                    $delta = (float) $logItem->quantity_delta;
                    if (abs($delta) < 0.0001) return 0.0;

                    // Пытаемся найти позицию приёмки с зафиксированным коэффициентом
                    $receptionItem = $logItem->receptionLog?->stoneReception?->items
                        ->firstWhere('product_id', $logItem->product_id);

                    if ($receptionItem) {
                        return $delta * $receptionItem->effectiveProdCost();
                    }

                    // Fallback: используем текущий коэффициент продукта
                    return $product ? $product->calculateWorkerPay($delta) : 0.0;
                });

                // Для отображения в сводной таблице — берём средневзвешенный коэффициент
                $effCoeffDisplay = $items
                    ->map(fn($li) => $li->receptionLog?->stoneReception?->items->firstWhere('product_id', $li->product_id)?->effective_cost_coeff)
                    ->filter()
                    ->avg() ?? $product?->prod_cost_coeff ?? 0;

                return [
                    'product'    => $product,
                    'quantity'   => $quantity,
                    'coeff'      => $effCoeffDisplay,
                    'is_undercut' => $isUndercut,
                    'prodCost'   => null, // вычисляется по-разному для каждой позиции
                    'pay'        => $pay,
                ];
            })
            ->filter(fn($row) => abs($row['quantity']) > 0.0001) // убираем нулевые строки
            ->sortBy(fn($row) => ($row['product']?->sku ?? '') . '_' . ($row['is_undercut'] ? '1' : '0'))
            ->values();
    }
}
