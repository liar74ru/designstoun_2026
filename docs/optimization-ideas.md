# Идеи для будущей оптимизации

## 1. Устранить лишние HTTP-запросы при синхронизации с МойСклад

**Файл:** `app/Services/Moysklad/MoySkladBaseService.php`

### Проблема

При каждом вызове `syncReception()` сервис делает отдельный GET-запрос на каждый продукт, чтобы получить его `meta`-объект:

```php
// Делает GET /entity/product/{id} только ради получения meta-структуры
protected function getEntityMeta(string $type, string $id): ?array
{
    $data = $this->get('/entity/' . $type . '/' . $id);
    return $data['meta'] ?? null;
}
```

Количество запросов на одну синхронизацию:

| Сценарий | Запросов |
|---|---|
| Создание техоперации (N продуктов) | ~N + 4 |
| Обновление техоперации (N продуктов) | ~N + 4 |
| Типичная приёмка (5 продуктов) | ~9 |

### Решение

`meta`-объект — это просто URL до сущности. Его можно строить локально, без запроса к API:

```php
protected function buildEntityMeta(string $type, string $id): array
{
    return [
        'href'      => $this->baseUrl . '/entity/' . $type . '/' . $id,
        'type'      => $type,
        'mediaType' => 'application/json',
    ];
}
```

Заменить все вызовы `$this->getEntityMeta($type, $id)` на `$this->buildEntityMeta($type, $id)` в `StoneReceptionSyncService`. Это сократит количество запросов с ~9 до **3**:
- `GET /entity/organization` (кешируется)
- `GET /entity/processing/metadata` (статусы, кешируется)
- `POST` или `PUT /entity/processing`

### Когда делать

Если пользователи жалуются на задержку при сохранении приёмки (> 2–3 сек). В текущем состоянии риск регрессии выше выигрыша.
