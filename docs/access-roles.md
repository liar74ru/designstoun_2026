# Система доступа и ролей

## Как определяется роль пользователя

Роль пользователя определяется **двумя источниками**:

| Источник | Поле | Где хранится |
|---|---|---|
| Флаг администратора | `users.is_admin` (bool) | таблица `users` |
| Должность | `workers.position` (string) | таблица `workers`, через `users.worker_id` |

Методы модели `User` (`app/Models/User.php`):

```php
isAdmin()  // $this->is_admin === true
isMaster() // $this->worker?->position === 'Мастер'
isWorker() // position in ['Пильщик', 'Галтовщик']
isCutter() // $this->worker?->position === 'Пильщик'
```

---

## Роли и доступ

### Администратор (`is_admin = true`)
- Полный доступ ко всему
- Может управлять работниками, товарами, настройками
- Может ставить ручные даты в приёмках/партиях
- Видит дашборд любого работника

### Мастер (`workers.position = 'Мастер'`)
- Приёмка камня (`stone-receptions.*`)
- Партии сырья (`raw-batches.*`)
- Поступления сырья (`supplier-orders.*`)
- Список работников (только своего отдела, если `department_id` задан)
- Просмотр дашбордов работников (только своего отдела)
- Редирект после логина → `stone-receptions.logs`

### Рабочий (`workers.position` in `['Пильщик', 'Галтовщик']`)
- Только свой дашборд выработки (`worker.dashboard`)
- Смена пароля (`workers.edit-user`)
- Редирект после логина → `worker.dashboard`

### Остальные должности
`Worker::POSITIONS` = `['Директор', 'Администратор', 'Мастер', 'Пильщик', 'Галтовщик', 'Приемщик', 'Разнорабочий', 'Кладовщик']`

Должности без учётных записей (нет `users` записи) не имеют доступа к системе.

---

## Middleware

Оба middleware применяются **глобально** ко всем авторизованным маршрутам (`bootstrap/app.php`).

### `WorkerOnly` (`app/Http/Middleware/WorkerOnly.php`)
- Срабатывает только если `isWorker() === true`
- Разрешённые маршруты: `worker.dashboard`, `worker.dashboard.by-id`, `workers.edit-user`, `workers.update-user`, `logout`
- Всё остальное → редирект на `worker.dashboard`

### `MasterOnly` (`app/Http/Middleware/MasterOnly.php`)
- Срабатывает только если `isMaster() === true`
- Белый список `ALLOWED_ROUTES` (~100 маршрутов): приёмки, партии, поступления, AJAX-эндпоинты, дашборды, смена пароля
- Всё остальное → редирект на `stone-receptions.logs`

**Важно:** при добавлении нового маршрута (включая `/api/*`) — добавь его имя в `ALLOWED_ROUTES` в `MasterOnly.php`.

---

## Проверки в коде

### Контроллеры
| Файл | Что проверяет |
|---|---|
| `AdminSettingController` | `abort_unless(isAdmin(), 403)` |
| `AuthenticatedSessionController` | редирект по роли после логина |
| `WorkerController` | мастер видит только свой отдел; только admin меняет `is_admin` |
| `CutterWorkerDashboardController` | admin видит всех; мастер — свой отдел; рабочий — только себя |
| `StoneReceptionController` | только admin ставит `manual_created_at` |
| `SupplierOrderController` | только admin ставит `manual_created_at` |
| `RawMaterialBatchController` | только admin ставит `manual_created_at` |

### Blade-шаблоны
| Файл | Что показывает |
|---|---|
| `layouts/partials/header.blade.php` | навигация по ролям |
| `workers/index.blade.php` | кнопки CRUD только для admin |
| `workers/edit-user.blade.php` | форма флага `is_admin` только для admin |
| `workers/dashboard/show.blade.php` | данные мастера vs рабочего |
| `components/admin-date-field.blade.php` | поле ручной даты только для admin |

---

## Отдел (department)

Мастер может быть привязан к отделу через `workers.department_id`. Если отдел задан:
- В `WorkerController::index()` мастер видит только работников своего отдела
- В `CutterWorkerDashboardController` мастер видит дашборды только своего отдела (403 для чужих)

---

## Тесты

- `tests/Feature/Auth/MasterAccessTest.php` — доступ мастера, middleware
- `tests/Feature/Auth/LoginTest.php` — логин по телефону/email, редиректы
- `tests/Feature/Worker/MasterDepartmentAccessTest.php` — отдел мастера
