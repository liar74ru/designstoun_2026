# Паттерн синхронизации модели с МойСклад

## Концепция

Каждая бизнес-единица (например, `StoneReception`), подлежащая синхронизации, **сама хранит** состояние синхронизации с МойСклад. Это даёт:
- Чёткую обратную связь прямо в UI объекта
- Независимое отслеживание для каждой записи
- Простой статус: «синхр» / «не синхр» — без привязки к чужим данным

Родительская сущность (`RawMaterialBatch`) **не хранит** данные о техоперации: это ответственность дочерней приёмки.

---

## Поля модели

```php
$table->string('moysklad_processing_id')->nullable();       // UUID техоперации в МойСклад
$table->string('moysklad_processing_name')->nullable();     // Имя техоперации (для отображения)
$table->string('moysklad_sync_status')->nullable();         // 'synced' | 'not_synced'
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
| `syncStatusLabel(): string` | «Синхр» / «Не синхр» |
| `syncStatusBadgeClass(): string` | `bg-success` / `bg-danger` |
| `markSynced(string $processingId, ?string $processingName = null)` | Поставить `synced`, очистить ошибку |
| `markSyncError(string $error)` | Поставить `not_synced`, записать ошибку |

Реализация: [app/Models/Concerns/HasMoyskladSync.php](../app/Models/Concerns/HasMoyskladSync.php).

---

## Паттерн синхронизации (сервисный уровень)

Логика синхронизации вынесена в `StoneReceptionSyncService::syncReception()`. Сервис приёмки вызывает её после транзакции:

```php
// StoneReceptionService::create() / update()
$this->syncService->syncReception($reception, $customName);
```

Сам `syncReception` выглядит так:

```php
public function syncReception(StoneReception $reception, ?string $customName = null): void
{
    $batch = $reception->rawMaterialBatch;
    if (!$batch) {
        return;
    }

    try {
        $reception->loadMissing('items.product', 'rawMaterialBatch.product');
        $description = $this->buildReceptionDescription($reception, $batch);

        if (!$reception->hasMoySkladProcessing()) {
            $result = $this->createProcessingForReception($reception, $customName, $description ?: null);
            if ($result['success']) {
                $reception->markSynced($result['processing_id'], $result['processing_name']);
            } else {
                $reception->markSyncError($result['message']);
            }
        } else {
            $result = $this->updateProcessingProducts(
                $reception->moysklad_processing_id,
                $reception->items,
                $reception->store_id ?? '',
                (float) $reception->raw_quantity_used,
                $batch->product->moysklad_id ?? '',
                $description ?: null
            );
            if ($result['success']) {
                $reception->markSynced($reception->moysklad_processing_id);
            } else {
                $reception->markSyncError($result['message']);
            }
        }
    } catch (\Exception $e) {
        $reception->markSyncError('Ошибка: ' . $e->getMessage());
    }
}
```

Вызывается **вне транзакции**, не блокирует основное действие.

### description техоперации

`buildReceptionDescription()` формирует поле `description` из логов приёмки: дата, получатель, дельты по товарам (со знаком + / −), пометка `(подкол)`. Передаётся при создании и каждом обновлении техоперации.

---

## Правило финального статуса

Финальный статус (например, `completed`) ставится **локально всегда**, независимо от результата синхронизации. Если синхронизация завершения с МойСклад не удалась — `sync_status = 'not_synced'` с сохранением ошибки. Пользователь видит предупреждение и может повторить вручную.

```php
// StoneReceptionService::markCompleted()
DB::transaction(function () use ($reception) {
    $reception->markAsCompleted();
    // обновление статуса партии...
});

$reception->refresh();

if ($reception->hasMoySkladProcessing()) {
    $result = $this->syncService->completeProcessing($reception->moysklad_processing_id);

    if ($result['success']) {
        $reception->markSynced($reception->moysklad_processing_id);
        return ['success' => true, 'message' => 'Приёмка завершена.'];
    }

    $reception->markSyncError($result['message']);
    return [
        'success' => false,
        'message' => 'Приёмка завершена локально, но ошибка синхронизации с МойСклад: ' . $result['message'],
    ];
}

return ['success' => true, 'message' => 'Приёмка завершена.'];
```

---

## Сброс статуса (reactivate)

`StoneReceptionService::resetStatus()` возвращает приёмку в активный статус:
1. Локально очищает все sync-поля (`moysklad_processing_id`, `moysklad_sync_status` и т.д.) и ставит `status = active`
2. Вызывает `$this->syncService->reactivateProcessing($processingId)` — переводит техоперацию в МойСклад обратно в статус «В работе» (берётся из `Setting::get('MOYSKLAD_IN_WORK_STATE')`)

Локальный сброс выполняется в транзакции и происходит **всегда**; синхронизация — после транзакции. Ошибка синхронизации возвращается как строка (не исключение).

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

Сервисы, работающие с техоперациями МойСклад (`WorkshopSyncService`, `StoneReceptionSyncService`), используют общий трейт `App\Services\Moysklad\Concerns\HandlesProcessingSync`. Он наследуется поверх `MoySkladBaseService` и предоставляет:

| Метод | Назначение |
|---|---|
| `calcProcessingSum(float $totalRubles, float $totalQty): int` | processingSum в копейках за единицу |
| `getProcessingStateHref(string $name): ?string` | href статуса техоперации, с кешем на запрос |
| `extractApiError(?array $body): string` | Текст ошибки из `errors[0].error` / `.title` |
| `fetchExistingPositionIds(string $processingId, string $section = 'products'): array` | Map `assortment_uuid → position_id` для секции `products` или `materials` |
| `updateProcessingState(string $processingId, string $stateName, string $context): array` | Перевод техоперации в указанный статус |
| `completeProcessing(string $processingId): array` | Перевод в `MOYSKLAD_DONE_STATE` |
| `reactivateProcessing(string $processingId): array` | Возврат в `MOYSKLAD_IN_WORK_STATE` |

Возврат всех методов: `['success' => bool, 'code' => string, 'message' => string]`.

Реализация: [app/Services/Moysklad/Concerns/HandlesProcessingSync.php](../app/Services/Moysklad/Concerns/HandlesProcessingSync.php).

---

## Применение в проекте

| Модель | Статус |
|---|---|
| `StoneReception` | Реализован |
| `Workshop` | Реализован |
| `RawMaterialBatch` | Реализован (синхронизация через перемещения, не техоперацию; trait моделей и UI-компонент общие, серверный trait `HandlesProcessingSync` неприменим) |
| `SupplierOrder` | Использует свой паттерн (заказ + приёмка через `MoySkladPurchaseOrderService` + `MoySkladSupplyService`); общий trait/компонент **не применяется** |
