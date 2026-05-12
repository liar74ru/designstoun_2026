# Спецификация: единый хаб синхронизаций с МойСклад

**Дата:** 2026-05-12
**Файл шаблона:** `resources/views/sync/index.blade.php`
**Маршрут:** `GET /sync` (`route('sync.index')`, под `can:manage-admin`)

## Цель

Переосмыслить страницу `/sync` как единую точку запуска и наблюдения всех глобальных (не привязанных к конкретной записи) операций синхронизации приложения с МойСклад. Дать визуально консистентные карточки операций со статусом последнего запуска, временем, длительностью, автором и текстом ошибки. Убрать избыточные элементы текущей страницы (плитки-дубли, декларативную таблицу, collapsible-обёртку).

## Скоуп

**Входит в скоуп:**
- 5 карточек глобальных синхронизаций (см. раздел «Состав»)
- Backend-инфраструктура: `SyncRunRepository` (Cache), JSON-ответы во всех 5 sync-actions, замер `duration_ms`
- Frontend: единый partial карточки, скрипт `sync-hub.js` (или inline `@push('scripts')`) для AJAX без перезагрузки
- Точечные фиксы текущего кода: GET→POST для двух маршрутов, удаление seman­tически неверного вызова stockSync из `OrderController::sync`

**Не входит в скоуп:**
- Per-record синхронизации (приёмки/партии/упаковки/поступления) — остаются на своих страницах
- История запусков (несколько последних) — храним только last-run
- Queue/Jobs/Webhooks
- Изменения в `MoySklad*Service`-классах сверх минимума: только добавление `count` в результат сервиса, где его сейчас нет

## Состав карточек

| Ключ | Заголовок | Подпись | Иконка | Аксент | Эндпоинт | Сервис |
|---|---|---|---|---|---|---|
| `products` | Товары и группы | Каталог и группы из МойСклад | `bi-box-seam` | primary | `POST products.sync` | `MoySkladService::syncGroups` + `syncProducts` |
| `counterparties` | Контрагенты | Поставщики и покупатели из МойСклад | `bi-people` | info | `POST counterparties.sync` | `MoySkladService::syncCounterparties` |
| `stores` | Склады | Места хранения из МойСклад | `bi-building` | success | `POST stores.sync` | `MoySkladService::syncStores` |
| `stocks` | Остатки по всем складам | Текущие остатки всех товаров на всех складах | `bi-stack` | warning | `POST products.stocks.sync-all-by-stores` | `StockSyncService::syncAllProductsStocksByStores` |
| `customer-orders` | Заказы покупателей | Активные заказы из МойСклад | `bi-bag` | danger | `POST orders.sync` | `CustomerOrderSyncService::pullActive` |

Источник правды для состава — массив `$operations` в `SyncController::index()` (или вынести в `config/sync_operations.php` по образцу `config/department_operations.php`).

## Структура карточки

**Layout:** `col-12 col-md-6 col-lg-4`, аксент-бордюр слева (`border-left: 4px solid var(--accent)`), `border-radius: .4rem`, hover-shadow.

**Состояния:**

| Состояние | Точка-индикатор | Бокс статуса (текст 1) | Бокс статуса (текст 2) | Кнопка |
|---|---|---|---|---|
| `idle` | серая | `— Не запускалась` | — | «Синхронизировать» (primary) |
| `success` | зелёная | `✓ Успех · {относит. время}` | `{items_count} · {duration} · {user_name}` | «Синхронизировать» (primary) |
| `error` | красная | `⚠ Ошибка · {относит. время}` | текст `message` (text-danger) | «Повторить» (primary) |
| `running` | синяя (анимация) | `⟳ Выполняется…` + spinner | — | заблокирована (`disabled`) |

**Время в боксе:**
- Отображение — относительное (`2 ч назад`, `5 мин назад`, `вчера`, `12.05.2026` для >7 дней)
- Tooltip (`title=`) — точная дата/время локали `dd.MM.YYYY HH:mm:ss`

**Длительность:** форматируется в `27 сек`, `2 мин 14 сек`, `1 ч 03 мин` (без миллисекунд для пользователя; миллисекунды только в хранилище).

## Backend-контракт

### `App\Services\Sync\SyncRunRepository`

Новый сервис, файл `app/Services/Sync/SyncRunRepository.php`:

```php
final class SyncRunRepository
{
    public function last(string $key): ?array
    {
        return cache()->get("sync:last-run:{$key}");
    }

    public function record(string $key, array $data): array
    {
        $enriched = $data + ['finished_at' => now()->toIso8601String()];
        cache()->forever("sync:last-run:{$key}", $enriched);
        return $enriched;
    }
}
```

`record()` возвращает обогащённый массив, чтобы контроллер мог отдать его сразу в JSON без повторного чтения из Cache.

**Ключ кэша:** `sync:last-run:{operation_key}`. **TTL:** `forever` (перезаписывается при следующем запуске).

**Структура хранимых данных:**
```json
{
  "status": "success" | "error",
  "message": "Загружено 1240 товаров",
  "items_count": 1240,
  "duration_ms": 27000,
  "user_name": "Иван П.",
  "finished_at": "2026-05-12T14:30:15+03:00"
}
```

### JSON-ответвление в контроллерах

В каждом из 5 sync-actions:

```php
public function sync(Request $request, SyncRunRepository $syncRuns): RedirectResponse|JsonResponse
{
    $start = microtime(true);
    $result = $this->moySkladService->syncCounterparties();
    $durationMs = (int) ((microtime(true) - $start) * 1000);

    $enriched = $syncRuns->record('counterparties', [
        'status'      => $result['success'] ? 'success' : 'error',
        'message'     => $result['message'],
        'items_count' => $result['count'] ?? null,
        'duration_ms' => $durationMs,
        'user_name'   => auth()->user()?->name,
    ]);

    if ($request->wantsJson() || $request->header('X-Sync-Ajax')) {
        return response()->json($enriched);
    }

    return redirect()->route('counterparties.index')
        ->with($result['success'] ? 'success' : 'error', $result['message']);
}
```

**Триггер JSON-режима:** заголовок `X-Sync-Ajax: 1` ИЛИ `Accept: application/json`. Это сохраняет существующие redirect-вызовы со страниц `counterparties.index`, `stores.index` и т. п.

### Сервисы — добавление `count`

Если у сервисов нет в результате `count`, добавить:
- `MoySkladService::syncCounterparties()` — `count` = `synced` (если уже есть `synced` — переименовать не нужно, маппить как `items_count` в контроллере)
- `MoySkladService::syncStores()` — аналогично
- `StockSyncService::syncAllProductsStocksByStores()` — кол-во затронутых товаров
- `CustomerOrderSyncService::pullActive()` — кол-во заказов

Существующие `result['message']` уже содержат строковое количество (например, «Синхронизировано: 1240») — оставляем для read-only отображения, но `items_count` нужен для отдельного поля и для будущей сортировки/фильтрации.

## Изменения маршрутов

**В файле [routes/web.php](routes/web.php):**

| Текущее | Новое | Причина |
|---|---|---|
| `Route::get('/products/sync/moysklad', ...)` | `Route::post(...)` | GET с побочными эффектами — небезопасно; нет CSRF |
| `Route::get('/products/groups/sync', ...)` | `Route::post(...)` | то же |

Оба остаются под `can:see-products`. На хабе они не используются напрямую, но на других страницах (например `products/index`) могут быть кнопки — обновить formhelpers (`method="POST"` + `@csrf`).

`stores.stocks.sync-all` ([routes/web.php:88](routes/web.php#L88)) — дубль `products.stocks.sync-all-by-stores`. **Не удаляем** (используется со страницы `stores/index`), но на хабе используем `products.stocks.sync-all-by-stores` как канонический.

## Изменения контроллеров

| Файл | Метод | Правка |
|---|---|---|
| `app/Http/Controllers/ProductController.php:91` | `syncFromMoySklad` | + замер времени, + `SyncRunRepository::record('products', ...)`, + JSON-ответвление |
| `app/Http/Controllers/ProductController.php:151` | `syncAllProductsStocks` | + замер, + `record('stocks', ...)`, + JSON |
| `app/Http/Controllers/CounterpartyController.php:23` | `sync` | + замер, + `record('counterparties', ...)`, + JSON |
| `app/Http/Controllers/StoreController.php:50` | `sync` | + замер, + `record('stores', ...)`, + JSON |
| `app/Http/Controllers/OrderController.php:26` | `sync` | **убрать `$stocks = $this->stockSync->syncAllProductsStocksByStores();`** (семантическая дыра — карточка «Заказы» не должна делать остатки); + замер; + `record('customer-orders', ...)`; + JSON |

## `SyncController`

```php
class SyncController extends Controller
{
    public function index(SyncRunRepository $syncRuns): View
    {
        $operations = collect(config('sync_operations'))->map(function ($op, $key) use ($syncRuns) {
            return $op + ['key' => $key, 'last_run' => $syncRuns->last($key)];
        })->values();

        return view('sync.index', compact('operations'));
    }
}
```

Новый файл `config/sync_operations.php` хранит метаданные 5 карточек (label, subtitle, icon, accent, endpoint).

## Frontend

### Новый шаблон `resources/views/sync/index.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Синхронизация с МойСклад')
@section('content')
<div class="container py-3 py-md-4">
    <x-page-header title="🔄 Синхронизация с МойСклад" :hide-mobile="true">
        <x-slot:actions>
            <a href="{{ route('home') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> На главную
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('partials.alerts')

    <div class="row g-3" id="sync-hub">
        @foreach ($operations as $op)
            @include('sync.partials.operation-card', ['op' => $op])
        @endforeach
    </div>
</div>
@endsection

@push('scripts')
    <script>/* sync-hub.js — см. ниже */</script>
@endpush

@push('styles')
    <style>/* минимальный CSS карточки — см. ниже */</style>
@endpush
```

### Новый partial `resources/views/sync/partials/operation-card.blade.php`

Карточка отрисовывается под `data-key="{{ $op['key'] }}"`, `data-endpoint="{{ route($op['route']) }}"`. Внутри:
- header (иконка + title + dot)
- subtitle
- status-box (рендерится по `$op['last_run']` или показывает `idle`)
- кнопка `[type=button][data-action=sync]`

### JS-скрипт хаба

Без зависимостей. Обработчик click на `[data-action=sync]`:
1. Найти ближайшую `[data-key]` карточку
2. Поставить в `running`-состояние (класс на карточке, spinner, disabled на кнопке)
3. `fetch(endpoint, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Sync-Ajax': '1', 'Accept': 'application/json' } })`
4. При response — переотрисовать status-box и dot из ответа, разблокировать кнопку, показать toast
5. На network error — переключить в `error` с message «Сетевая ошибка»

Утилита `formatRelative(iso)` — простой switch для минут/часов/дней.

### CSS (минимальный)

```css
.sync-card { border: 1px solid var(--bs-border-color); border-left: 4px solid; border-radius: .4rem; }
.sync-card--primary { border-left-color: var(--bs-primary); }
.sync-card--info    { border-left-color: var(--bs-info);    }
.sync-card--success { border-left-color: var(--bs-success); }
.sync-card--warning { border-left-color: var(--bs-warning); }
.sync-card--danger  { border-left-color: var(--bs-danger);  }
.sync-card:hover    { box-shadow: 0 .25rem .75rem rgba(0,0,0,.08); }
.sync-dot           { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.sync-dot--success  { background: var(--bs-success); }
.sync-dot--error    { background: var(--bs-danger);  }
.sync-dot--idle     { background: var(--bs-secondary); }
.sync-dot--running  { background: var(--bs-info); animation: pulse 1s infinite; }
@keyframes pulse { 50% { opacity: .4; } }
```

## Что удаляется из старого шаблона

- Верхний ряд из 4 «навигационных плиток» (`row mb-4` с градиентными фонами)
- Collapsible-обёртка с шевроном (`#section-toggle`, `#section-collapse`, `localStorage` ключ `sync_section_collapsed`)
- Заголовки секций «Загрузка справочников», «Остатки», «Документы», «Разделы»
- Таблица «Отправка документов в МойСклад» (декларативная, без действий)
- Нижний блок «Разделы с синхронизацией» (6 ссылок)
- Inline `<style>` с gradient-фонами `.bg-primary { background: linear-gradient(...); }` и `slideInUp`-анимацией

## Обработка ошибок

**Сетевые ошибки на фронте:** fetch.catch → переключаем карточку в `error` с message «Сетевая ошибка», кнопка → «Повторить».

**Ошибки сервера (5xx):** контроллер возвращает 500 + JSON `{status: error, message: '...'}` (см. существующие try/catch в Moysklad*Service — большинство уже возвращают `['success' => false, 'message' => ...]` без выброса исключений).

**Конкурентный запуск.** Если пользователь дважды нажмёт на одну карточку — defended кнопкой `disabled` на время запроса. Глобальный lock между разными пользователями не нужен (страница админская, в один момент времени активен один админ).

**`OrderController::sync` без stocks.** На странице `orders.index` сейчас тоже есть кнопка «Синхронизировать заявки» (текущий submit). После правки она тоже перестанет дёргать остатки. Если эту функциональность нужно сохранить отдельно — у нас уже есть карточка «Остатки», админ может нажать её отдельно. **Если на страницах с заказами действительно нужен «два-в-одном» — добавить отдельную кнопку или объяснить пользователю.** В текущем скоупе разделяем строго.

## Тестирование (Pest)

Создать `tests/Feature/Sync/SyncHubTest.php`:
- `it('shows hub page with all operations')` — `actingAs($admin)->get(route('sync.index'))` → assert see 5 заголовков карточек
- `it('returns JSON when X-Sync-Ajax header set')` — POST на `counterparties.sync` с заголовком → assert JSON структура
- `it('still redirects on regular POST')` — без заголовка → assert `redirect`
- `it('records last-run after successful sync')` — мокаем `MoySkladService`, дёргаем эндпоинт, читаем `SyncRunRepository::last('counterparties')`, проверяем поля
- `it('records error last-run when service fails')` — mocked service returns `['success' => false]` → assert `status === 'error'`
- `it('order sync no longer dispatches stocks sync')` — мокаем `StockSyncService`, проверяем что не вызвался

**Тест UI-скрипта не пишем** — это JS без бизнес-логики, проверяется вручную.

## Точки фикса вне скоупа дизайна, но требующие внимания

1. На страницах `products.index`, `stores.index`, `counterparties.index` есть свои кнопки «Синхронизировать» — после JSON-ответвления они продолжают работать как redirect, но **формы для GET-маршрутов `products.sync` / `products.groups.sync` нужно переписать на POST с CSRF** (после смены метода в `routes/web.php`).
2. Дубль маршрута `stores.stocks.sync-all` оставлен как есть; в будущем можно объединить с `products.stocks.sync-all-by-stores` (отдельная задача).

## Открытые вопросы

Нет. Все определены в ходе брейнсторминга (5 карточек, AJAX-режим, last-run в Cache, без агрегатов записей, без отдельной карточки «Группы», без нижних связанных ссылок, только время последнего запуска).
