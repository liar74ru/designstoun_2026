<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StoneReception;
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

        // Загружаем приёмки пильщика за период
        // with() — жадная загрузка, чтобы не было N+1 запросов
        $receptions = StoneReception::with(['items.product', 'store', 'receiver'])
            ->where('cutter_id', $worker->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->orderBy('created_at', 'desc')
            ->get();

        // Считаем сводку по продуктам за период.
        // Группируем все позиции всех приёмок по product_id.
        //
        // Результат: [product_id => ['product' => ..., 'quantity' => ..., 'pay' => ...]]
        $summary = $this->buildProductSummary($receptions);

        // Итоговая зарплата = сумма по всем продуктам
        $totalPay = $summary->sum('pay');

        return view('worker-dashboard.show', compact(
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
    private function buildProductSummary($receptions)
    {
        // Собираем все позиции всех приёмок в один плоский список
        $allItems = $receptions->flatMap(fn($reception) => $reception->items);

        // Группируем по product_id
        return $allItems
            ->groupBy('product_id')
            ->map(function ($items, $productId) {
                $product  = $items->first()->product;
                $quantity = $items->sum(fn($item) => (float) $item->quantity);
                $pay      = $product ? $product->calculateWorkerPay($quantity) : 0;

                return [
                    'product'  => $product,
                    'quantity' => $quantity,
                    'coeff'    => $product?->prod_cost_coeff ?? 1.0,
                    'pay'      => $pay,
                ];
            })
            ->sortByDesc('pay')  // самые доходные позиции вверху
            ->values();
    }
}
