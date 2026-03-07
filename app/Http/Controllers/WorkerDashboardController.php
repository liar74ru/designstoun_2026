<?php

namespace App\Http\Controllers;

use App\Models\Product;
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
        // - обычный работник видит только себя
        if ($user->isAdmin() && $workerId) {
            $worker = Worker::findOrFail($workerId);
        } else {
            // Для не-администратора — только его собственный профиль
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
        $logs = ReceptionLog::with(['items.product', 'stoneReception.store', 'receiver'])
            ->where('cutter_id', $worker->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->orderBy('created_at', 'desc')
            ->get();

        // Для обратной совместимости с шаблоном — $receptions теперь это логи
        $receptions = $logs;

        // Считаем сводку по продуктам за период на основе дельт лога
        $summary = $this->buildProductSummary($logs);

        // Итоговая зарплата = сумма по всем продуктам
        $totalPay = $summary->sum('pay');

        return view('workers.dashboard.show', compact(
            'worker',
            'receptions',
            'summary',
            'totalPay',
            'dateFrom',
            'dateTo',
        ));
    }

    /**
     * Строим сводку: сколько какого продукта произведено и сколько заработано.
     *
     * Используем Laravel Collection методы — это PHP-аналог SQL GROUP BY,
     * но в памяти, что удобно когда данных немного (недельная выборка).
     */
    private function buildProductSummary($logs)
    {
        // Собираем все позиции всех логов в один плоский список.
        // quantity_delta может быть отрицательной (корректировка вниз) — это нормально.
        $allItems = $logs->flatMap(fn($log) => $log->items);

        // Группируем по product_id и суммируем дельты
        return $allItems
            ->groupBy('product_id')
            ->map(function ($items, $productId) {
                $product  = $items->first()->product;
                // Суммируем дельты — итог и есть фактически произведённое количество
                $quantity = $items->sum(fn($item) => (float) $item->quantity_delta);
                $pay      = $product ? $product->calculateWorkerPay($quantity) : 0;

                return [
                    'product'  => $product,
                    'quantity' => $quantity,
                    'coeff'    => $product?->prod_cost_coeff ?? 1.0,
                    'pay'      => $pay,
                ];
            })
            ->filter(fn($row) => abs($row['quantity']) > 0.0001) // убираем нулевые строки
            ->sortByDesc('pay')
            ->values();
    }
}
