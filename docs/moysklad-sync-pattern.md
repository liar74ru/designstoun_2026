# Паттерн синхронизации модели с МойСклад

## Концепция

Каждая бизнес-единица (например, `StoneReception`), подлежащая синхронизации, **сама хранит** состояние синхронизации с МойСклад. Это даёт:
- Чёткую обратную связь прямо в UI объекта
- Независимое отслеживание для каждой записи
- Простой статус: «синхр» / «не синхр» — без привязки к чужим данным

Родительская сущность (`RawMaterialBatch`) **не хранит** данные о техоперации: это ответственность дочерней приёмки.

---

## HTTP-клиент: таймауты и повторы

Все запросы идут через `MoySkladBaseService` (`get`, `getMany`, `post`, `put`, `delete`) —
свой `Http::` в сервисах не заводить.

- Таймаут ответа `MOYSKLAD_TIMEOUT` (20 с) и соединения `MOYSKLAD_CONNECT_TIMEOUT` (5 с).
- До 3 попыток. Повторяются: **429** (пауза — из заголовка `X-Lognex-Retry-TimeInterval`,
  не больше 5 с), обрыв соединения до отправки (cURL 6/7), а для GET/PUT/DELETE ещё
  **502/503/504**. Пауза без заголовка — `MOYSKLAD_RETRY_DELAY_MS` (500 мс), удваивается.
- **POST не повторяется при 5xx и таймауте ответа**: документ мог создаться, повтор дал бы дубль.
- Исчерпав попытки, клиент возвращает последний ответ; сетевое исключение, как и раньше,
  ловит вызывающий метод (`try` есть во всех).
- `getMany` (пул) повторяет так же, пауза не блокирует остальные страницы.
- Тесты: токен в `phpunit.xml` пуст, `Tests\TestCase` включает `Http::preventStrayRequests()` —
  тест без `Http::fake` не дойдёт до рабочего МойСклад. Пауза повторов в тестах — 0.
  Проверки клиента — `tests/Feature/Moysklad/MoySkladClientRetryTest.php`.

---

## Поля модели

```php
$table->string('moysklad_processing_id')->nullable();       // UUID техоперации в МойСклад
$table->string('moysklad_processing_name')->nullable();     // Имя техоперации (для отображения)
$table->string('moysklad_sync_status')->nullable();         // 'synced' | 'pending' | 'not_synced'
$table->text('moysklad_sync_error')->nullable();            // Текст ошибки последней синхронизации
$table->timestamp('synced_at')->nullable();                 // Время последней попытки синхронизации
```

Поля добавляются в `$fillable`.

---

## Trait `HasMoyskladSync`

Все константы и helper-методы вынесены в трейт `App\Models\Concerns\HasMoyskladSync`. Подключение в модель:

```php
use App\Models\Concerns\HasMoyskladSync;

class Workshop extends Model
{
    use HasMoyskladSync;
    // ...
}
```

Трейт предоставляет:

| Что | Назначение |
|---|---|
| `SYNC_STATUS_SYNCED` / `SYNC_STATUS_NOT_SYNCED` | Константы статусов |
| `hasMoySkladProcessing(): bool` | Есть ли UUID техоперации |
| `hasSyncError(): bool` | Есть ли текст ошибки |
| `isSynced(): bool` | `moysklad_sync_status === SYNCED` |
| `syncStatusLabel(): string` | «Синхр» / «Ожидает» / «Не синхр» |
| `syncStatusBadgeClass(): string` | `bg-success` / `bg-secondary` / `bg-danger` |
| `markSynced(string $processingId, ?string $processingName = null)` | Поставить `synced`, очистить ошибку |
| `markSyncError(string $error)` | Поставить `not_synced`, записать ошибку |
| `SYNC_STATUS_PENDING`, `isSyncPending()`, `markSyncPending()` | Документ ждёт в очереди; ошибка сбрасывается |

Реализация: [app/Models/Concerns/HasMoyskladSync.php](../app/Models/Concerns/HasMoyskladSync.php).

---

## Очередь: приёмка, цех, партии

Приёмка, цех и партии отправляются в МойСклад задачами очереди (`app/Jobs`), работник МойСклад не
ждёт. Заказы поставщикам и заявки синхронные: их логика зависит от мгновенного ответа.

```
Сервис: транзакция → queueSync() (markSyncPending + dispatch)
Задача: push*() → sync*() (создать/обновить) → статус техоперации по статусу документа
```

**Одна задача на документ, состояние читается при выполнении.** Задача не знает, какое действие её
поставило: она приводит техоперацию к текущей записи (`HandlesProcessingSync::applyDocumentState`).
Поэтому порядок «завершили → сбросили» не важен, а `ShouldBeUnique` не нужен: лишний запуск — это
обычный PUT, а отброшенная задача унесла бы `alsoRefresh`. После неудачного обновления статус
техоперации не меняется.

**Один воркер.** `numprocs=1` в `docker/supervisord.conf` даёт порядок FIFO и исключает параллельную
обработку документа. Увеличивать нельзя без блокировки по документу.

**Идемпотентность создания.** POST не повторяется вслепую: таймаут мог оборвать ответ на принятый
запрос, и повтор создал бы второй документ с задвоением остатков. Поэтому у техоперации есть
`externalCode` (`DocumentNaming::externalCode()`), а перед каждым POST идёт поиск по нему
(`adoptExistingProcessing`). Нашлась — документ привязывается и идёт как обновление. Поиск не удался —
POST не отправляется. Вне прода код получает префикс окружения: локальная база работает с тем же
аккаунтом МойСклад, и её id совпали бы с прод-документами.

У партий то же самое: код `movement_{id}` первичного движения, поиск в `pushBatch`.

**Количество партии.** Перемещение равно партии: `RawMaterialBatch::moveQuantity()` = «количество» минус
возвраты на склад-источник (`merged_into_move`), минимум 0.001. Читается при выполнении, поэтому повтор не
применит изменение дважды; дельты в МойСклад не отправляются.

| Действие | В МойСклад |
|---|---|
| Создание, правка, корректировка | исходное перемещение = `moveQuantity()` |
| Передача | исходное уменьшается, у новой партии своё перемещение |
| Возврат на склад-источник | исходное уменьшается, отдельного нет |
| Возврат на другой склад | исходное без изменений, отдельное из цеха |

Несколько партий в одном действии (передача, возврат, split при приёмке) ставятся по порядку: новая партия,
родитель, потом приёмка.

**Повторы** (`SyncDocumentJob`, 6 попыток, паузы 30 с, 1, 2, 5, 15 мин):

| Исход | Статус документа | Что дальше |
|---|---|---|
| Успех | `synced` | — |
| 429, 5xx, сеть, таймаут | `pending` | `release()` с паузой |
| Прочие 4xx, у позиции нет `moysklad_id`, товара нет в МойСклад | `not_synced` + текст ошибки | кнопка повтора |
| Исчерпаны попытки | `not_synced` + текст ошибки | кнопка повтора |
| Воркер упал или вышел по таймауту | `not_synced` (`failed()`) | кнопка повтора |

Признак временного сбоя ставит `MoySkladBaseService` на каждом запросе (`$transientFailure`): `get()`
глотает ошибки, и по итогу метода их не отличить от постоянных. Успех с таким признаком тоже
повторяется: мог не прочитаться некритичный GET, например id позиций.

**Документ уходит целиком или не уходит.** Позицию приёмки или цеха нельзя пропустить: нет `moysklad_id` или
не прочиталась мета товара — это ошибка до POST/PUT с именем товара (`HandlesProcessingSync::positionMoyskladId`,
`positionMeta`), техоперация в МойСклад остаётся прежней. Отбрасываются только строки с нулевым количеством.

**Удаление.** Записи уже нет, поэтому `DeleteProcessingInMoysklad` и `DeleteMoveInMoysklad` получают значения. Без id ищет по
`externalCode`: документ могли удалить, пока его создание ждало в очереди. 404 — успех. Показать ошибку
негде, она остаётся в логе и `failed_jobs`.

**Сроки.** `--timeout=300` меньше `retry_after=360`, иначе задачу возьмут второй раз, пока идёт первая.
`stopwaitsecs=330` даёт дождаться её при деплое. Локально воркер запускает `make start-queue`
(или `composer dev`), без него документы остаются «Ожидает».

**Тесты.** В `phpunit.xml` драйвер `sync`: задача выполняется внутри запроса и `release()` ничего не
делает. Поведение очереди проверяет `Queue::fake()`, исходы задачи — `withFakeQueueInteractions()`
(`tests/Feature/Moysklad/ProcessingSyncJobTest.php`, `BatchSyncJobTest.php`).

---

## Паттерн синхронизации (сервисный уровень)

`syncReception()` / `syncWorkshop()` создают или обновляют техоперацию, сами пишут `markSynced` /
`markSyncError` и возвращают `['success', 'message', 'retryable', 'created']`. Вызывает их задача
очереди, а не сервис документа.

Оба — обёртки над `ProcessingSyncService::sync()` / `push()` (абстрактная база `StoneReceptionSyncService`
и `WorkshopSyncService`). В базе — только то, что зависит от МойСклад и этого порядка: поиск по `externalCode`,
POST с повтором при занятом имени (`createProcessing`), PUT (`updateProcessing`), имя по неделе, статус «в работе»,
группировка позиций (`groupPositions`), id существующих позиций (`withExistingIds`), остатки после отправки.
**Состав документа** — склады, продукты, материалы, processingSum, примечание — строит подкласс замыканием
`$buildPayload`: он зависит от модели, у приёмки и цеха разный и пока не объединяется (модели ещё меняются).

### description техоперации

`buildReceptionDescription()` формирует поле `description` из логов приёмки: дата, получатель, дельты по товарам (со знаком + / −), пометка `(подкол)`. Передаётся при создании и каждом обновлении техоперации.

---

## Правило финального статуса

Финальный статус (например, `completed`) ставится **локально всегда**, независимо от МойСклад.
`markCompleted()` выполняет транзакцию и вызывает `queueSync()`; задача сначала обновляет позиции, затем
переводит техоперацию в `MOYSKLAD_DONE_STATE`. Не удалось — `not_synced` с текстом ошибки и кнопка повтора.

---

## Сброс статуса (reactivate)

`resetStatus()` возвращает документ в активный статус в транзакции, связь с техоперацией сохраняется.
Если техоперация есть, задача вернёт её в `MOYSKLAD_IN_WORK_STATE`. Правка активного документа шлёт этот
статус тоже: настройка пуста — шаг пропускается.

---

## UI-блок МойСклад: компонент `<x-moysklad-sync-status>`

Реализация: [resources/views/components/moysklad-sync-status.blade.php](../resources/views/components/moysklad-sync-status.blade.php).

Минимальный вариант:

```blade
<x-moysklad-sync-status
    :model="$entity"
    :sync-route="route('entity.sync', $entity)" />
```

Атрибуты:

| Атрибут | По умолчанию | Назначение |
|---|---|---|
| `model` | — | Модель, использующая `HasMoyskladSync` |
| `sync-route` | — | URL для POST-формы ручной синхронизации |
| `wrapper` | `card` | `card` (Bootstrap card) или `info-block` (для левых колонок с info-block-сеткой) |
| `show-button` | `true` | Показывать ли форму ретрая (например, скрыть для архивных партий) |
| `empty-text` | `Техоперация не создана` | Текст в empty-состоянии |
| `create-text` | `Создать техоперацию` | Текст кнопки если ещё нет UUID |
| `sync-text` | `Синхронизировать с МойСклад` | Текст кнопки если UUID уже есть |

Логика статус-бейджа: показывается только если `moysklad_sync_status` установлен (не null). Если синхронизации ещё не было — бейджа нет.

---

## Trait `HandlesProcessingSync` для sync-сервисов техопераций

Сервисы, работающие с техоперациями МойСклад (`ProcessingSyncService` — база приёмки и цеха, `ProcessingDeleteService`), используют общий трейт `App\Services\Moysklad\Concerns\HandlesProcessingSync`. Он наследуется поверх `MoySkladBaseService` и предоставляет:

| Метод | Назначение |
|---|---|
| `calcProcessingSum(float $totalRubles, float $totalQty): int` | processingSum в копейках за единицу |
| `getProcessingStateHref(string $name): ?string` | href статуса техоперации, с кешем на запрос |
| `extractApiError(?array $body): string` | Текст ошибки из `errors[0].error` / `.title` |
| `fetchExistingPositionIds(string $processingId, string $section = 'products'): array` | Map `assortment_uuid → position_id` для секции `products` или `materials` |
| `updateProcessingState(string $processingId, string $stateName, string $context): array` | Перевод техоперации в указанный статус |
| `completeProcessing(string $processingId): array` | Перевод в `MOYSKLAD_DONE_STATE` |
| `reactivateProcessing(string $processingId): array` | Возврат в `MOYSKLAD_IN_WORK_STATE` |
| `findProcessingByExternalCode()`, `adoptExistingProcessing()` | Поиск техоперации прошлой попытки перед POST |
| `applyDocumentState()` | Статус техоперации по статусу документа |

Возврат всех методов: `['success' => bool, 'code' => string, 'message' => string]`.

Реализация: [app/Services/Moysklad/Concerns/HandlesProcessingSync.php](../app/Services/Moysklad/Concerns/HandlesProcessingSync.php).

---

## Применение в проекте

| Модель | Статус |
|---|---|
| `StoneReception` | Реализован, через очередь |
| `Workshop` | Реализован, через очередь |
| `RawMaterialBatch` | Реализован, через очередь (перемещения, не техоперация; trait моделей и UI-компонент общие, серверный trait `HandlesProcessingSync` неприменим) |
| `SupplierOrder` | Использует свой паттерн (заказ + приёмка через `MoySkladPurchaseOrderService` + `MoySkladSupplyService`); общий trait/компонент **не применяется** |
