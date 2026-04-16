<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ограничивает доступ для мастеров.
 * Мастер может посещать только:
 *   - приёмку камня (stone-receptions.*)
 *   - перемещение сырья (raw-batches.*, raw-movement.*)
 *   - поступления сырья (supplier-orders.*)
 *   - список и просмотр работников (workers.index, workers.show)
 *   - связанные AJAX-эндпоинты
 *   - страницу смены пароля (/workers/{id}/edit-user)
 *   - выход из системы
 */
class MasterOnly
{
    /** Маршруты, доступные мастеру (по имени маршрута) */
    private const ALLOWED_ROUTES = [
        // Приёмка камня
        'stone-receptions.index',
        'stone-receptions.create',
        'stone-receptions.store',
        'stone-receptions.show',
        'stone-receptions.edit',
        'stone-receptions.update',
        'stone-receptions.destroy',
        'stone-receptions.logs',
        'stone-receptions.copy',
        'stone-receptions.reset-status',
        'stone-receptions.mark-completed',
        'stone-receptions.update-item-coeff',
        'stone-receptions.refresh-item-coeffs',
        'stone-receptions.sync',

        // Партии и перемещение сырья
        'raw-batches.index',
        'raw-batches.create',
        'raw-batches.store',
        'raw-batches.show',
        'raw-batches.edit',
        'raw-batches.update',
        'raw-batches.destroy',
        'raw-batches.destroy-new',
        'raw-batches.copy',
        'raw-batches.transfer.form',
        'raw-batches.transfer',
        'raw-batches.return.form',
        'raw-batches.return',
        'raw-batches.adjust.form',
        'raw-batches.adjust',
        'raw-batches.adjust-remaining.form',
        'raw-batches.adjust-remaining',
        'raw-batches.archive',
        'raw-batches.mark-used',
        'raw-batches.mark-in-work',
        'raw-batches.sync',
        'raw-movement.store',

        // Поступления сырья
        'supplier-orders.index',
        'supplier-orders.create',
        'supplier-orders.store',
        'supplier-orders.edit',
        'supplier-orders.update',
        'supplier-orders.destroy',
        'supplier-orders.sync',
        'supplier-orders.sync-confirm',
        'supplier-orders.force-sync',
        'api.supplier-orders.next-number',

        // AJAX-эндпоинты, используемые в формах приёмки и партий
        'api.worker.batches',
        'api.worker.next-batch-number',
        'api.products.tree',
        'api.products.coeff',
        'api.batch.receptions',
        'api.batch.active-reception',
        'api.products.stocks',

        // Работники (просмотр списка — мастер видит в хедере)
        'workers.index',
        'workers.show',
        'worker.dashboard.by-id',

        // Смена пароля и выход
        'workers.edit-user',
        'workers.update-user',
        'logout',

        // Дашборд мастера
        'home',
        'master.dashboard',
        'master.dashboard.by-id',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Если пользователь не мастер — пропускаем (middleware не применяется)
        if (! $user || ! $user->isMaster()) {
            return $next($request);
        }

        // Проверяем текущий маршрут
        $currentRoute = $request->route()?->getName();

        if (in_array($currentRoute, self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        // Мастер пытается попасть на недоступную страницу — редиректим на журнал приёмок
        return redirect()->route('stone-receptions.logs');
    }
}
