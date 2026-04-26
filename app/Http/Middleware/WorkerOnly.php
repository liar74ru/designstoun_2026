<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ограничивает доступ для рабочих специальностей (Пильщик, Галтовщик и т.д.).
 * Рабочий может посещать только:
 *   - свой дашборд (/my-work)
 *   - страницу смены пароля (/workers/{id}/edit-user)
 *   - выход из системы
 */
class WorkerOnly
{
    /** Маршруты, доступные рабочим (по имени маршрута) */
    private const ALLOWED_ROUTES = [
        'worker.dashboard',
        'worker.dashboard.by-id',
        'workers.edit-user',
        'workers.update-user',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Если пользователь не рабочий, или у него есть более высокая роль — пропускаем
        if (! $user || ! $user->isWorker() || $user->isMaster() || $user->isAdmin()) {
            return $next($request);
        }

        // Проверяем текущий маршрут
        $currentRoute = $request->route()?->getName();

        if (in_array($currentRoute, self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        // Рабочий пытается попасть на чужую страницу — редиректим на его дашборд
        return redirect()->route('worker.dashboard');
    }
}
