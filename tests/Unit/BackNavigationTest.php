<?php

use App\Support\BackNavigation;

/**
 * Формы — «проходные» страницы: кнопка «Назад» на них не возвращает (resources/js/back-link.js).
 */

test('форма распознаётся по последнему сегменту имени маршрута', function (string $route) {
    expect(BackNavigation::isTransient($route))->toBeTrue();
})->with([
    'raw-batches.create',
    'raw-batches.edit',
    'raw-batches.transfer.form',
    'raw-batches.adjust.form',
    'raw-batches.return.form',
    'orders.internal.create',
    'orders.internal.edit',
    'supplier-orders.sync-confirm',
    'workers.create-user',
    'workers.edit-user',
    'admin.departments.presets.edit',
]);

test('карточки и списки формами не считаются', function (?string $route) {
    expect(BackNavigation::isTransient($route))->toBeFalse();
})->with([
    'raw-batches.show',
    'raw-batches.index',
    'orders.show',
    'stone-receptions.logs',
    'admin.enterprise-dashboard',
    // «form» только целым сегментом
    'platform.index',
    'login',
    null,
]);
