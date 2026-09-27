<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Гость не попадает ни на один маршрут приложения — одна проверка на все маршруты.
 *
 * Раньше в каждом модуле был свой тест «недоступно без авторизации» — около шестидесяти
 * копий, и маршрут, добавленный без такого теста, не проверялся вовсе. Здесь маршруты
 * берутся из роутера, поэтому новый маршрут попадает под проверку сам.
 */

/** Маршруты, открытые гостю намеренно. */
const GUEST_ROUTES = ['login', 'storage/{path}', 'up'];

/** Маршруты под guard'ом auth — всё, кроме намеренно открытых. */
function guardedRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->reject(fn (RoutingRoute $r) => in_array($r->uri(), GUEST_ROUTES, true))
        ->all();
}

test('все маршруты, кроме входа, закрыты middleware auth', function () {
    $open = collect(guardedRoutes())
        ->reject(fn (RoutingRoute $r) => in_array('auth', $r->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $r) => implode('|', $r->methods()) . ' ' . $r->uri())
        ->values()
        ->all();

    expect($open)->toBe([]);
});

test('гость с любого маршрута уходит на страницу входа', function () {
    expect(guardedRoutes())->not->toBeEmpty();

    $leaks = [];

    foreach (guardedRoutes() as $route) {
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        // Параметры подставляем заглушкой: auth срабатывает раньше привязки моделей.
        $uri = '/' . ltrim(preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');

        $response = $this->call($method, $uri);

        if (! $response->isRedirect(route('login'))) {
            $leaks[] = "{$method} {$uri} → {$response->getStatusCode()}";
        }
    }

    expect($leaks)->toBe([]);
});

test('JSON-запрос гостя получает 401, а не редирект', function () {
    $this->getJson(route('api.products.tree'))->assertUnauthorized();
});
