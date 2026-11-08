# Система доступа и ролей

## Как определяется роль пользователя

Роль пользователя определяется **двумя источниками**:

| Источник | Поле | Где хранится |
|---|---|---|
| Флаг администратора | `users.is_admin` (bool) | таблица `users` |
| Должность | `workers.position` (string) | таблица `workers`, через `users.worker_id` |

Методы модели `User` (`app/Models/User.php`):

```php
isAdmin()                // $this->is_admin
isMaster()               // position === 'Мастер'
isWorker()               // position === 'Работник'
isLoginBlocked()         // работник в архиве (см. «Вход»)
accessibleDepartmentIds() // null — админ (все отделы), иначе основной отдел + pivot
primaryDepartmentId()    // основной отдел — дефолт селектов в формах
```

`Worker::POSITIONS` = `['Администратор', 'Мастер', 'Помощник мастера', 'Работник', 'Разнорабочий']`.
Пользователь без привязанного работника и без `is_admin` видит только главную.

---

## Роли и доступ

Что видит каждая роль, задаёт **реестр операций** `config/department_operations.php`, а не код:
у операции один из флагов видимости.

- `admin_only` — только администратор.
- `positions_always_visible` — всегда видна перечисленным позициям (например, `worker-dashboard`).
- `configurable_positions` — администратор включает её позициям по отделам: матрица в `admin/departments/{id}`,
  таблица `department_operation_settings`.

Проверка — одна точка `App\Support\OperationAccessor::canSee($user, $key)`. Для каждой операции в
`AppServiceProvider::boot()` есть Gate `see-{key}`: на маршрутах — `->middleware('can:see-<key>')`,
в шаблонах — `@can('see-<key>')`. Чисто админские разделы — `can:manage-admin`. Эндпоинты для нескольких
операций сразу — составной Gate (`use-product-api`, `OperationAccessor::canSeeAny()`).

### Администратор (`is_admin = true`)
- Видит все операции реестра и все отделы
- Управляет работниками, товарами, настройками, отделами и их правами
- Может ставить ручные даты в приёмках/партиях
- Видит дашборд любого работника

### Мастер и помощник мастера
- Операции — по матрице прав своих отделов (`configurable_positions`)
- Списки — только своих отделов (`accessibleDepartmentIds()`); чужие записи смотреть можно, менять нельзя
  (раздел «Отдел»)
- Мастер видит дашборды работников своего отдела (`WorkerPolicy::viewDashboard`), помощник — нет

### Работник и разнорабочий
- Свой дашборд выработки (`worker.dashboard`, `positions_always_visible`)
- Смена пароля (`workers.edit-user`)

### Стартовая страница
`User::startRouteName()` — одно правило и после логина, и при заходе на `/`:
- Админ — главная.
- Мастер и помощник мастера — `stone-receptions.logs`. Без операции `stone-receptions` в своих отделах — `worker.dashboard`, чтобы не получить 403.
- Все остальные — `worker.dashboard`.
- Пользователь без доступа к дашборду (нет привязанного работника, позиция вне реестра) — главная.

### Остальные должности
`Worker::POSITIONS` = `['Директор', 'Администратор', 'Мастер', 'Пильщик', 'Галтовщик', 'Приемщик', 'Разнорабочий', 'Кладовщик']`

Должности без учётных записей (нет `users` записи) не имеют доступа к системе.

### Вход

- **Архив закрывает вход** и обрывает действующую сессию (`User::isLoginBlocked()`,
  `LoginRequest`, `EnsureLoginAllowed`). Возврат из архива открывает вход снова.
- **Админ в архиве** блокируется, только если есть другой действующий админ: без исключения система
  могла бы остаться без администратора.
- **Лимиты:** 5 попыток в минуту на логин с адреса (форматы одного телефона считаются одним логином) и
  30 попыток в минуту с адреса (`throttle:login`).
- **Пароль** — не короче 8 символов при создании и смене; старые короткие пароли продолжают работать до смены.
- **`is_admin`** не входит в `$fillable`, присваивается только явно: `WorkerService::updateUser`, сидер.

---

## Middleware

Своих middleware ролей нет (прежние `WorkerOnly` и `MasterOnly` с белым списком маршрутов заменены реестром
операций и Gate `can:see-<key>`, см. «Роли и доступ»). В `app/Http/Middleware` один класс:

### `EnsureLoginAllowed`
- Подключён ко всей группе `web` (`bootstrap/app.php`)
- Работника перевели в архив, пока он в системе (`User::isLoginBlocked()`) — сессия и «запомнить меня»
  обрываются, редирект на вход с ошибкой

**Новый маршрут** — зарегистрируй операцию в реестре и защити `can:see-<key>` (правило — в CLAUDE.md).

---

## Проверки в коде

### Контроллеры
| Файл | Что проверяет |
|---|---|
| `AdminSettingController` | маршруты под `can:manage-admin` |
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

Работник может быть задействован **в нескольких отделах**:
- `workers.department_id` — **основной** отдел: дефолт селектов отдела в формах операций
  и наследование `department_id` в документах (партии, приёмки, цех, заказы поставщикам).
- `department_worker` — все отделы работника. **Инвариант:** основной отдел всегда присутствует
  в pivot (поддерживается хуком `Worker::booted()` и `WorkerService::syncDepartments()`).

Отделы задаются в форме работника чекбоксами, основной — радио-кнопкой
(`resources/views/partials/worker-departments.blade.php`).

Кто что назначает в карточке работника (`WorkerRequest` + `WorkerService`):
- Админ — любые отделы и любую должность, в том числе себе.
- Не-админ — только отделы из `accessibleDepartmentIds()` и только рядовые должности;
  мастерские (`Worker::MASTER_POSITIONS`) назначает админ, текущую должность коллеги можно оставить.
  Отделы работника вне его зоны (включая основной) показаны только для чтения и при сохранении
  не меняются.
- Своя карточка не-админа — отделы и должность не меняются (поля исключаются из запроса),
  иначе мастер с `see-workers` выдал бы себе права во всех отделах.

Если отделы заданы:
- `User::accessibleDepartmentIds()` возвращает **все** отделы работника → index-страницы
  показывают записи всех его отделов
- `OperationAccessor::canSee()` даёт доступ, если должность разрешена хотя бы в одном из отделов
- В `WorkerController::index()` мастер видит работников всех своих отделов
- В `CutterWorkerDashboardController` мастер видит дашборды работников своих отделов (403 для чужих)
- В формах операций работники фильтруются по выбранному отделу
  (`resources/js/worker-picker.js`, `data-department-ids`), чекбокс «все работники» снимает фильтр

### Отдел новых и переносимых записей

Смотреть чужие записи можно, менять — нет (`DepartmentAccess::allowsAny`, политики `modify`).
Отдел, который запись **получает** (создание, перенос при правке), проверяет
`DepartmentAccess::canAssign()`: админ — любой и без отдела, не-админ — только свой.

- Приёмка, цех, партия: итоговый отдел после подстановок (`resolveDepartmentId`,
  `WorkshopService::formDepartmentId`) проверяется в контроллере → ошибка поля `department_id`.
- Поступление: отдел берётся из приёмщика (`SupplierOrderService::resolveDepartmentId`) →
  ошибка поля `receiver_id`.
- Приёмка оформляется только на «свою» партию — правило `App\Rules\ModifiableBatch`
  (при правке прежняя партия приёмки проходит без проверки).
- Работник: не-админ не создаёт работника без отдела и не снимает последний отдел
  (`WorkerRequest::after`).
- Заявки покупателей: мастер ставит и снимает только свои отделы
  (`OrderService::assignableDepartments`, `resolveDepartments`).
- **Старые записи без отдела** правятся как раньше: правка без смены отдела — не перенос.

---

## Тесты

- `tests/Feature/Auth/MasterAccessTest.php` — доступ мастера, middleware
- `tests/Feature/Auth/LoginTest.php` — логин по телефону/email, редиректы
- `tests/Feature/Worker/MasterDepartmentAccessTest.php` — отдел мастера
- `tests/Feature/Worker/WorkerAssignmentAccessTest.php` — назначение отделов и должностей
- `tests/Feature/Access/DepartmentAssignTest.php` — отдел новых и переносимых записей
- `tests/Feature/Access/RecordDepartmentAccessTest.php` — изменение чужих записей и заявок (403)
