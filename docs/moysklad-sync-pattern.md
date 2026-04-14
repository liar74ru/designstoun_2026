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

## Константы

```php
const SYNC_STATUS_SYNCED     = 'synced';
const SYNC_STATUS_NOT_SYNCED = 'not_synced';
```

---

## Helper-методы

```php
public function hasMoySkladProcessing(): bool
{
    return !empty($this->moysklad_processing_id);
}

public function hasSyncError(): bool
{
    return !empty($this->moysklad_sync_error);
}

public function isSynced(): bool
{
    return $this->moysklad_sync_status === self::SYNC_STATUS_SYNCED;
}

public function syncStatusLabel(): string
{
    return $this->isSynced() ? 'Синхр' : 'Не синхр';
}

public function syncStatusBadgeClass(): string
{
    return $this->isSynced() ? 'bg-success' : 'bg-danger';
}
```

---

## Методы изменения sync-состояния

```php
public function markSynced(string $processingId, ?string $processingName = null): void
{
    $this->update([
        'moysklad_processing_id'   => $processingId,
        'moysklad_processing_name' => $processingName ?? $this->moysklad_processing_name,
        'moysklad_sync_status'     => self::SYNC_STATUS_SYNCED,
        'moysklad_sync_error'      => null,
        'synced_at'                => now(),
    ]);
}

public function markSyncError(string $error): void
{
    $this->update([
        'moysklad_sync_status' => self::SYNC_STATUS_NOT_SYNCED,
        'moysklad_sync_error'  => $error,
        'synced_at'            => now(),
    ]);
}
```

---

## Паттерн контроллера

```php
private function sync*Processing(Model $entity): void
{
    $service = app(MoySkladProcessingService::class);
    try {
        if (!$entity->hasMoySkladProcessing()) {
            // Первая синхронизация — создаём техоперацию
            $result = $service->createProcessingForReception($entity);
            if ($result['success']) {
                $entity->markSynced($result['processing_id'], $result['processing_name']);
            } else {
                $entity->markSyncError($result['message']);
                Log::warning('sync: не удалось создать техоперацию', [...]);
            }
        } else {
            // Повторная синхронизация — обновляем техоперацию
            $result = $service->updateProcessingProducts(
                $entity->moysklad_processing_id,
                $entity->items,
                $entity->store_id ?? '',
                (float) $entity->raw_quantity_used,
                $entity->rawMaterialBatch->product->moysklad_id ?? ''
            );
            if ($result['success']) {
                $entity->markSynced($entity->moysklad_processing_id);
            } else {
                $entity->markSyncError($result['message']);
                Log::warning('sync: не удалось обновить техоперацию', [...]);
            }
        }
    } catch (\Exception $e) {
        Log::error('sync: исключение', ['error' => $e->getMessage()]);
        $entity->markSyncError('Ошибка: ' . $e->getMessage());
    }
}
```

Вызывается **вне транзакции**, не блокирует основное действие.

---

## Правило финального статуса

Финальный статус (например, `completed`) ставится **локально всегда**, независимо от результата синхронизации. Если синхронизация завершения с МойСклад не удалась — `sync_status = 'not_synced'` с сохранением ошибки. Пользователь видит предупреждение и может повторить вручную.

```php
public function markCompleted(Model $entity)
{
    $entity->markAsCompleted(); // Бизнес-статус ставим локально всегда

    if ($entity->hasMoySkladProcessing()) {
        $result = app(MoySkladProcessingService::class)
            ->completeProcessing($entity->moysklad_processing_id);

        if ($result['success']) {
            $entity->markSynced($entity->moysklad_processing_id);
            return back()->with('success', 'Завершено.');
        } else {
            $entity->markSyncError($result['message']);
            return back()->with('warning',
                'Завершено локально, но ошибка синхронизации: ' . $result['message']);
        }
    }

    return back()->with('success', 'Завершено.');
}
```

---

## UI-паттерн блока МойСклад в `show.blade.php`

```blade
<div class="info-block">
    <div class="info-block-header d-flex justify-content-between align-items-center">
        <span class="small fw-semibold text-muted">
            <i class="bi bi-cloud me-1"></i>МойСклад
        </span>
        @if($entity->moysklad_sync_status)
            <span class="badge {{ $entity->syncStatusBadgeClass() }} small">
                {{ $entity->syncStatusLabel() }}
            </span>
        @endif
    </div>
    <div class="info-block-body">
        @if($entity->hasSyncError())
            <div class="small text-warning-emphasis">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Ошибка:</strong> {{ $entity->moysklad_sync_error }}
            </div>
        @elseif($entity->isSynced())
            <div class="small text-success">
                <i class="bi bi-check-circle me-1"></i> Синхронизировано
                @if($entity->moysklad_processing_name)
                    · <span class="text-muted">{{ $entity->moysklad_processing_name }}</span>
                @endif
            </div>
            @if(auth()->user()->is_admin)
                <div class="text-muted mt-1" style="font-size:.72rem;word-break:break-all">
                    <i class="bi bi-fingerprint me-1"></i>
                    <code style="font-size:.7rem">{{ $entity->moysklad_processing_id }}</code>
                </div>
            @endif
        @else
            <div class="small text-muted">
                <i class="bi bi-cloud-slash me-1"></i>Техоперация не создана
            </div>
        @endif
        @if($entity->synced_at)
            <div class="text-muted mt-2" style="font-size:.72rem">
                <i class="bi bi-clock-history me-1"></i>
                Последняя синхр.: {{ $entity->synced_at->format('d.m.Y H:i') }}
            </div>
        @endif
    </div>
</div>
```

**Логика статус-бейджа в шапке:** показывается только если `moysklad_sync_status` установлен (не null). Если синхронизации ещё не было — бейджа нет.

---

## Применение в проекте

| Модель | Статус |
|---|---|
| `StoneReception` | Реализован |
