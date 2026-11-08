# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Проект

Designstoun 2026 — система учёта производства каменных изделий (резка камня). Партии сырья, приёмка продукции, работники, синхронизация с МойСклад.

**Стек:** PHP 8.4, Laravel 12, PostgreSQL, Bootstrap 5.3, Alpine.js, Pest

---

## Правила работы

1. Перед правкой — изучи похожие файлы; следуй стилю кода
2. Не добавляй фичи сверх запрошенного
3. После изменений — запусти тесты: `make test` (PostgreSQL); по ходу работы можно `make test-fast`
4. Если новый функционал затрагивает контроллер, сервис или бизнес-логику — предложи написать тесты (Pest, в `tests/Feature/`), не создавай их без явного подтверждения

---

## Команды

Все команды доступны через `Makefile` (полный список — `make help`).

| Задача | Команда |
|---|---|
| Тесты | `make test` (= `php artisan test`, PostgreSQL `designstoun_test`, ~2 мин) |
| Быстрые тесты | `make test-fast` (SQLite in-memory, `--parallel`, ~15 с; ошибки типов PG не ловит) |
| JS-тесты | `make test-js` (= `npm test`, Vitest, `tests/js/`) |
| Один тест | `php artisan test --filter=<TestName>` или `php artisan test tests/Feature/Path/File.php` |
| Покрытие | `make test-coverage` |
| Линт | `make lint` / `make lint-fix` (Pint, стиль — `pint.json`) |
| Статанализ | `make stan` (larastan level 5, старое — в `phpstan-baseline.neon`) |
| Backend dev | `make start` (Laravel serve) |
| Frontend dev | `make start-frontend` (= `npm run dev`, Vite) |
| Воркер очереди | `make start-queue` (отправка приёмок, цеха и партий в МойСклад) |
| Сборка фронта | `npm run build` |
| Миграции | `make migrate` / `make migrate-status` / `make rollback` |
| Сиды | `make seed` |
| Пересоздать БД | `make fresh` (⚠ удаляет все данные) |
| Логи | `make log` |
| Очистить кэш | `make cache-clear` |
| Tinker | `make console` |

Альтернатива для всего dev-стека сразу: `composer dev` (concurrent: serve + queue + pail + vite).

---

## Тесты

- Фреймворк: **Pest 4.3** + `pest-plugin-laravel`
- Конфиг: `phpunit.xml`; БД для тестов — PostgreSQL `designstoun_test` (хост и пользователь — из `.env`,
  без `--parallel`: у `liar` нет `CREATEDB`), в CI (`.github/workflows/ci.yml`) — `postgres:16`.
  `Tests\TestCase` не даёт запустить feature-тесты на другой PG-базе: `RefreshDatabase` её стёр бы.
  SQLite — только `make test-fast`. Id в тестовых данных — настоящие (`stores.id`/`counterparties.id` — uuid)
- Фильтры списков из адреса — через `App\Support\QueryParam` (`idFilter`, `uuidFilter`, `idValues`,
  `date`): неверное значение считается незаданным, а не роняет запрос на PostgreSQL. Правило
  `exists:` в валидации — с `integer`/`uuid` перед ним
- Структура:
  - `tests/Feature/<Module>/` — интеграционные (модули: Auth, Moysklad, Workshop, RawBatch, StoneReception, Worker, AdminSettings, MasterDashboard, ProductController, RawMovementStore, Stock, Store, SupplierOrder, Counterparty)
  - `tests/Unit/` — юнит-тесты
  - `tests/Helpers/` — общие хелперы
  - `tests/Pest.php` — бутстрап
- Один тест: `php artisan test --filter=<имя теста>`

---

## Архитектура

Проект использует двухслойную архитектуру:
- Controllers: обработка HTTP, вызов сервисов, формирование ответа
- Services: бизнес-логика, оркестрация операций
- Model (Eloquent): доступ к данным, запросы к БД 
- Model содержат только отношения, scopes, accessor'ы
- Отношение — с нативным типом и PHPDoc `@return HasMany<Item, $this>` (`BelongsTo<…>` и т.д.):
  без него larastan видит `Model`, и обращения к связанной модели не проверяются
- МойСклад: все операции через `app/Services/Moysklad*.php`
- Общее у приёмки и цеха — `App\Services\ProductionDocumentService` (абстрактная база
  `StoneReceptionService` и `WorkshopService`): правило, одинаковое для обоих, пишется там один раз.
  Синхронизация — `App\Services\Moysklad\ProcessingSyncService`: в базе только код МойСклад, состав
  документа (склады, позиции, processingSum, примечание) — в подклассах, пока модели не устоялись
- Трейты:
  - `app/Traits/`: `ManagesStock`, `HandlesBatchStock`
  - `app/Models/Concerns/HasMoyskladSync` — общий код синхронизации для моделей
  - `app/Services/Moysklad/Concerns/HandlesProcessingSync` — общий код техопераций

Контроллеры работают только через сервисы.
Сервисы для работы с БД используют Model (Eloquent).
Прямой доступ к БД из контроллеров запрещен.

---

## Доступ и роли

Подробная документация: [docs/access-roles.md](docs/access-roles.md)

| Роль | Позиция (`Worker.position`) | Доступ |
|---|---|---|
| Администратор | `is_admin = true` (без позиции либо `Администратор`) | Полный, видит все операции реестра |
| Мастер | `Мастер` | Настраивается per-department в матрице операций |
| Помощник мастера | `Помощник мастера` | Настраивается per-department в матрице операций |
| Работник | `Работник` | Только `worker-dashboard` (`positions_always_visible`) |
| Разнорабочий | `Разнорабочий` | Только `worker-dashboard` |

`Worker.position` — **одно значение** (не массив). Список валидных значений в `Worker::POSITIONS`.

`Worker` может состоять **в нескольких отделах**: `workers.department_id` — основной отдел
(дефолты форм и наследование `department_id` в документах), pivot `department_worker` — все отделы.
Инвариант «основной отдел всегда в pivot» держат `Worker::booted()` и `WorkerService::syncDepartments()`.

### Реестр операций и Policy

**Все операции верхнего меню/прав** регистрируются в `config/department_operations.php`. Поля операции:
- `label`, `icon`, `route`/`url`, `route_pattern` — UI (иконка в шапке).
- `group` (1–5) — цвет группы плитки в шапке (подложка и иконка, активная залита цветом;
  палитра `.nav-g1…5` в `layouts/partials/header.blade.php`). Порядок ключей реестра — порядок
  плиток. `short_label` — подпись плитки, если `label` не влезает в 52px.
- Один из трёх флагов видимости:
  - `admin_only: true` — операция только для админа (не показывается в матрице настроек отдела).
  - `positions_always_visible: ['<позиция>', …]` — захардкоженная видимость для перечисленных позиций (нельзя выключить).
  - `configurable_positions: ['<позиция>', …]` — позиции, которые администратор настраивает per-department в `admin/departments/{id}` через таблицу-матрицу.

Доступ проверяется через `App\Support\OperationAccessor::canSee($user, $operationKey)` — единая точка истины. Для каждого ключа реестра в `AppServiceProvider::boot()` зарегистрирован Gate `see-{$key}`. На роутах: `->middleware('can:see-<operation>')`. Чисто админские разделы — `->middleware('can:manage-admin')`. На views: `@can("see-{$key}")`.

Хранилище per-department прав — таблица `department_operation_settings` (поля: `department_id`, `operation_key`, `enabled` (вычисляется автоматически), `config` JSONB с ключом `positions`).

### Правило при добавлении новой операции / маршрута

При добавлении любой новой страницы/иконки/группы маршрутов:
1. Зарегистрируй ключ операции в [config/department_operations.php](config/department_operations.php) с одним из трёх флагов видимости.
2. Защити маршруты middleware `can:see-<key>` (или `can:manage-admin` для админских).
3. Для `configurable_positions`-операций ничего больше не нужно — UI-матрица в `admin/departments/show` подхватит новую строку автоматически, админ задаст права per-department.
4. Если новая операция должна быть всегда доступна определённой роли — используй `positions_always_visible`.
5. Сбрасывать кэш разрешений вручную не нужно — `Department::forgetOperationsCache()` чистит все per-operation ключи.
6. AJAX-эндпоинты, относящиеся к операции, защищай тем же `can:see-<key>` (см. примеры в `routes/web.php` для `api.worker.batches`, `api.worker.next-batch-number` и т.п.).
7. Если эндпоинт обслуживает **сразу несколько** операций (например, `api.products.*` нужен формам приёмки, цеха, партий и заказов поставщику), заведи составной Gate по образцу `use-product-api`: список ключей — константой в `OperationAccessor`, проверка — через `OperationAccessor::canSeeAny()`. Псевдо-операцию в реестр для этого добавлять **нельзя** — она попадёт и в меню шапки, и в матрицу прав отдела.

### Себестоимость отдела: две разные модели

Обе читаются **только** через `App\Support\DepartmentSettings` — напрямую `Setting::get()` для
этих значений не использовать. Обе кэшируются и сбрасываются одним
`Department::forgetSettingsCache()`.

#### 1. Накладные расходы — таблица `department_expenses`

- Произвольный набор строк на отдел: `name` + `amount` (₽/м²). Состав и количество у каждого
  отдела свои; админ ведёт список в карточке отдела. Новый отдел стартует с пустым списком.
- **Наследования нет.** Глобальных ключей накладных больше не существует.
- Чтение — `DepartmentSettings::overheadPerUnit(?int)`, сумма строк. Кэш `dept.{id}.expenses`.
- Отдел не определён → **0**. Поэтому вызывающий обязан передавать
  `StoneReception::effectiveDepartmentId()` (`свой → отдел партии → отдел пильщика`), а не голый
  `department_id`.
- Сохранение — `App\Services\DepartmentExpenseService::sync()`, replace-all в транзакции:
  пустые строки отбрасываются, пустое имя → «Расход №N», пустая сумма → 0.
- Порядок строк — `orderBy('id')`; поля `sort_order` в проекте нет нигде.

#### 2. Числовые настройки с фолбэком — таблица `department_settings`

- Реестр ключей — [config/department_settings.php](config/department_settings.php): группа
  `worker` (`PIECE_RATE`, `HOURLY_RATE` — ставка часов ремонтных работ, снапшот в `worker_hour_entries`) и группа `master` (`MASTER_BASE_RATE`). `MASTER_UNDERCUT_RATE` убрана
  (3.48.0): подкол мастеру — правило отдела с `master_coeff_delta`.
- Цепочка: `настройка отдела → глобальный Setting → default в коде`. Строка отсутствует или
  пуста → наследование. Кэш `dept.{id}.settings`; правка глобальной настройки подхватывается
  сразу (у неё свой кэш `setting.<KEY>`).
- Методы принимают `?int $departmentId`: у админских и исторических документов отдела может не
  быть, тогда берутся глобальные значения.
- Добавление нового параметра: добавь ключ в конфиг — форма в `admin/departments/show` и
  whitelist валидации подхватят его автоматически.

#### 3. Правила-модификаторы отдела — таблица `department_modifiers`

Подкол, торцовка, бонус за плитку-маску и мелкая плитка — не флаги в коде, а строки БД
на уровне отдела. Наследования нет (как у накладных расходов), **новый отдел стартует с
пустым списком** — правил нет, надбавки не применяются. Набор по умолчанию —
`config/production_modifiers.php`, проставляется через `App\Services\DepartmentModifierService`
(его же зовёт `DepartmentsTableSeeder`).

- **Коэффициент = коэффициент продукта + сумма сработавших правил.** Сработать может несколько
  правил сразу; сложение коммутативно, поэтому порядка применения у правил нет.
- Правило задаёт слагаемое **отдельно на пильщика и на мастера** (`worker_coeff_delta`,
  `master_coeff_delta`); пустое значение — на эту роль правило не влияет. Штраф — отрицательное
  число.
- `trigger`: `manual` (чекбокс в форме) или `sku` (автоматически по маске `sku_pattern`,
  сегменты через `*`). `available_when_batch_sku` ограничивает доступность ручного правила по
  SKU партии. `applies_to`: `reception` / `workshop` / `both`.
- Расчёт — **только** через `App\Support\ModifierEngine` и `App\Support\ItemCost`; формул в
  сервисах и моделях больше нет. `ItemCost::compute()` считает от коэффициента продукта,
  `computeFromBase()` — от базы, пришедшей из формы (иначе бонус SKU задваивается при каждом
  сохранении).
- Позиция хранит **базу и результат раздельно**: `base_cost_coeff` (без надбавок) и
  `effective_cost_coeff`. Обратно база не вычисляется.
- Применённые правила копируются в снапшот `production_item_modifiers` (две nullable-FK на
  позицию приёмки/цеха — полиморфных связей в проекте нет). Правка или удаление правила
  задним числом не переписывает уже посчитанные зарплаты.
- Кэш `dept.{id}.modifiers`, сбрасывается тем же `Department::forgetSettingsCache()`.
- CRUD правил — в карточке отдела: список в `admin/departments/show`, форма на отдельных
  страницах `admin/departments/modifiers/{create,edit}` (`Admin\DepartmentModifierController`,
  роуты в `scopeBindings()`-группе под `can:manage-admin`). Палитра цветов плашек —
  `DepartmentModifier::COLORS`, набор иконок — `DepartmentModifier::ICONS`; обе константы
  и рисуют форму, и служат whitelist'ом валидации в `DepartmentModifierRequest`.
- **Метки правил в шаблонах — только `@include('partials.modifier-badges')`** по снапшоту
  позиции (`$item->modifiers` или `$row['modifiers']`). Параметры: `$variant`
  (`badge` — иконка с названием, `icon` — только иконки для тесных карточек), `$font`,
  `$class`. Своей разметки плашек не писать: раньше «подкол / торцовка / < 50мм» были
  выписаны в пяти шаблонах, и копии разошлись. Вид зеркалит `badge()` из
  `modifier-picker.js`, цвет текста считает `DepartmentModifier::textColorFor()`.
- Сводки дашбордов группируются по **подписи набора правил** — отсортированным ключам
  снапшота (`WorkerDashboardService::modifierSignature()`), а не по булевым колонкам.
  Позиция документа ищется по `product_id` через мемоизированную карту: у строки лога
  ссылки на позицию нет, и один товар в двух позициях с разными правилами схлопнется.

**Дефолты ставок нигде не дублировать.** Единственный источник —
`config/department_settings.php`. В JS они приходят партиалом
[partials/production-rates-js.blade.php](resources/views/partials/production-rates-js.blade.php)
в `window.ProductionRates` — вместе с картой «отдел → правила» (`modifiers.byDepartment`).
Формула для превью — [resources/js/rate-formula.js](resources/js/rate-formula.js): зеркало
`RateFormula` и `ModifierEngine` (`resolve`, `effectiveCoeff`, `manualRules`), подключено
глобально через `app.js`. Вписывать числа в шаблон или в JS нельзя — копии разъезжаются,
и превью перестаёт совпадать с сохранённым значением. Обе копии формулы проверяются одной
фикстурой `tests/fixtures/rate-formula.json` (Pest `RateFormulaFixtureTest` + Vitest
`tests/js/`, `make test-js`): меняешь расчёт — меняй пример там.

**Правила в формах.** Чекбоксы ручных правил и плашки sku-правил рисует
[resources/js/modifier-picker.js](resources/js/modifier-picker.js) в контейнер
`.modifier-picker` (атрибуты `data-scope`, `data-department-id`, `data-batch-sku`,
`data-input-name`, `data-active-keys`), а не Blade: отдел меняется прямо на странице.
Формы шлют массив ключей `products[i][modifiers][]` / `items[i][modifiers][]`.
При пересчёте ручной выбор восстанавливается из снапшота позиции —
`ModifierEngine::manualKeysFromSnapshot()`.
В правке коэффициентов на show приёмки и цеха (`data-editable-auto="1"`) sku-правила тоже
чекбоксы: снятое сервер вычисляет `ModifierEngine::disabledSkuKeys()` и хранит у позиции в
`disabled_modifier_keys` — его передают в `resolve()` и при `refreshItemCoeffs`, иначе правило
вернётся по SKU.

### Ставки за единицу продукции

Общая формула — `App\Support\RateFormula::stepped($rate, $coeff, $step)`:
`ОКРУГЛВНИЗ((ставка + ставка×17%×коэф) / шаг) × шаг`. При коэффициенте 0 → базовая ставка.
Шаг — настройка отдела (по умолчанию 10, ≤ 0 → 10), свой у пильщика и у мастера.

Коэффициент выводить **только** через `RateFormula::formatCoeff()` (в JS —
`RateFormula.formatCoeff()`): значение правила задаёт админ, колонка `decimal(8,4)`, и
`number_format($coeff, 1)` показывает 1.75 как «1,8».

| | Пильщик | Мастер |
|---|---|---|
| Атрибут МойСклад | `prodCostCoeff` | `masterCostCoeff` |
| Колонка | `products.prod_cost_coeff` | `products.master_cost_coeff` |
| Базовая ставка | `PIECE_RATE` (per-department) | `MASTER_BASE_RATE` (per-department) |
| Шаг округления | `PIECE_ROUND_STEP` (per-department) | `MASTER_ROUND_STEP` (per-department) |
| Надбавки | правят сам коэффициент | правят сам коэффициент (`master_coeff_delta` правил) |

Атрибут `masterCostCoeff` заводится в МойСклад вручную; его отсутствие или пустое значение → `0`
→ базовая ставка. Надбавки, зависящие от SKU (мелкая плитка), задаются через коэффициент продукта;
флаг `is_small_tile` на деньги не влияет и остаётся UI-индикатором.

**В миграциях не вызывай методы моделей с бизнес-формулами** — инлайнь формулу
(тесты гоняют `migrate:fresh`, и старая миграция не должна ломаться при эволюции модели).
Пример: `2026_04_22_000002_add_master_fields_to_stone_reception_items.php`.

### Доступ к чужим записям

- `WorkerPolicy@editSelf` — смена пароля: `workers.edit-user`/`workers.update-user` доступны самому пользователю или админу.
- `WorkerPolicy@viewDashboard` — `worker.dashboard.by-id` / `master.dashboard.by-id`: админ — любой; сам работник — свой; мастер — работники своего отдела.
- **Видимость по отделу:** index-страницы фильтруются через `User::accessibleDepartmentIds()` (админ — все, остальные — только свой отдел).
- **Чужие записи смотреть можно, менять нельзя.** Политики `modify` (`StoneReceptionPolicy`,
  `WorkshopPolicy`, `RawMaterialBatchPolicy`, `SupplierOrderPolicy`, `WorkerPolicy::modify`) → единая
  точка `App\Support\DepartmentAccess`: запись должна относиться к одному из отделов пользователя
  (основной + pivot). Отдел приёмки и цеха — по цепочке `effectiveDepartmentId()`; отдел не
  определился → менять можно всем. Каждое изменяющее действие начинается с
  `$this->authorize('modify', $record)`; у FormRequest правки — та же проверка в `authorize()`,
  иначе валидация ответит раньше отказа. Кнопки таких действий в шаблонах — под
  `@can('modify', $record)`. Новый изменяющий маршрут записи — добавь в датасет
  `tests/Feature/Access/RecordDepartmentAccessTest.php`.
- **Отдел, который запись получает** (создание, перенос при правке), — `DepartmentAccess::canAssign()`:
  админ — любой и без отдела, не-админ — только свой. Проверяй итоговый отдел после всех
  подстановок (форма → партия → работник), не только поле формы. Правка без смены отдела —
  не перенос: старые записи без отдела правятся как раньше. Приёмка — только на «свою» партию
  (`App\Rules\ModifiableBatch`). Заявки: у `OrderController` `findForUser()` — первой строкой,
  до валидации. Подробно — [docs/access-roles.md](docs/access-roles.md).

---

## Бизнес-логика

### Жизненный цикл партии сырья

`new → in_work → confirmed → used | returned → archived`

- Синхронизация МойСклад: приёмка → техоперация, партия → перемещение, через очередь; см. [docs/moysklad-sync-pattern.md](docs/moysklad-sync-pattern.md)
- Ошибка синхронизации → `batch.moysklad_sync_error`, на show-странице кнопка retry

### Жизненный цикл приёмки

`active → completed → processed | error`

### Модуль цеха (Workshop)

- Модели: `Workshop`, `WorkshopItem`, `WorkshopLog`, `WorkshopLogItem`
- Сервис: `app/Services/WorkshopService.php`
- Синхронизация с МойСклад: `app/Services/Moysklad/WorkshopSyncService.php`
- Бывший модуль «Упаковка» (создан миграцией `2026_05_01_000001_create_packagings_tables.php`, переименован миграцией `2026_07_19_000001_rename_packagings_to_workshops.php`)

### Модуль заявок покупателей (Order)

- Все правила (синхронизация, статусы, очередь, окно производства, раздача остатка, позиции,
  изменения состава, видимость) — [docs/orders.md](docs/orders.md).
- `orders.kind`: `customer` — заявка из МойСклад, `internal` — внутренний заказ полуфабриката
  другому отделу (`InternalOrderService`, в МойСклад не выгружается). Ссылки — по `orders.uuid`;
  всё, что трогает строки из выгрузки (удаление в синке и т.п.), ограничивай `Order::customer()`.

### Модуль заказов поставщикам (SupplierOrder)

- Модели: `SupplierOrder`, `SupplierOrderItem`
- Сервис: `app/Services/SupplierOrderService.php`
- Синхронизация с МойСклад: `app/Services/Moysklad/SupplierOrderSyncService.php` поверх `MoySkladPurchaseOrderService`
- Контроллер: `SupplierOrderController`

## Справка (docs/*.md)

Правила разделов описаны в `docs/<раздел>.md` (orders, stone-receptions, workshops, pay,
raw-batches, supplier-orders, products, enterprise-dashboard, settings — только админ). Тот же файл в приложении — страница
`help.show` (кнопка «?» в шапке раздела, оглавление — `help.index`, иконка в шапке сайта).

- Реестр страниц — `App\Services\HelpService::PAGES` (файл, заголовок, операции реестра для доступа,
  роут «назад»). Доступ — `OperationAccessor::canSeeAny()`; псевдо-операцию «справка» не заводить.
- Всё начиная с `## Для разработчиков` в приложении отрезается — там таблица «правило → код → тесты».
- Ссылки: `[текст](raw-batches.md)` — другая страница справки, `[текст](app:<ключ>)` — экран из
  `HelpService::LINKS`. Нет доступа к цели → остаётся текст. Неизвестный ключ ловит `HelpTest`.
- Кнопка раздела — `@include('partials.help-button', ['page' => '<ключ>'])`.
- **Меняешь правило раздела — обнови его docs-файл** (текст для пользователей и таблицу для
  разработчиков). Новый раздел — добавь страницу в `PAGES`.

## МойСклад API

- Имена документов: `DocumentNaming::weeklyName('ПРЕФИКС', $n)`; коллизия → `DocumentNaming::nextSuffix($name)` + retry
- Ответ сервиса: `['success' => bool, 'code' => '...', 'message' => '...']`
- Текст ошибки: `errors[0]['error']` (не `title`); не бросать исключения — возвращать массив
- HTTP — только через `MoySkladBaseService` (таймауты, повторы 429/5xx; POST при 5xx не
  повторяется). Тесты: токен пуст, `Http::preventStrayRequests()` в `Tests\TestCase` —
  всякий HTTP в тесте через `Http::fake`. См. [docs/moysklad-sync-pattern.md](docs/moysklad-sync-pattern.md)
- **Приёмка, цех и партии — через очередь** (`app/Jobs`, вызов — `queueSync()` сервиса документа),
  статус `pending` → `synced` | `not_synced`. Воркер один (`docker/supervisord.conf`), число процессов
  не увеличивать: на это рассчитаны порядок задач и поиск по `externalCode` перед POST. Перемещение
  равно партии (`RawMaterialBatch::moveQuantity()`), дельты не отправлять. Заказы и заявки синхронные.
  Локально воркер — `make start-queue`
- Префиксы: `ПРОГ` — приёмка сырья, `ТО` — техоперация обработки, `ЦЕХ` — техоперация цеха
- Паттерн синхронизации: [docs/moysklad-sync-pattern.md](docs/moysklad-sync-pattern.md)
- Формирование processingSum: [docs/processing-sum.md](docs/processing-sum.md)

---

## UI — ключевые правила

- Двойной рендеринг: `d-none d-md-block` (десктоп) + `d-md-none` (мобильный)
- Цвет карточки по типу камня: `Product::getColorBySku()` → `border-left + background + '18'`;
  цвета — таблица `sku_colors` (цвет → JSON-список префиксов «01-07», «04-07»; «01» — цвет группы, если у «01-xx» нет своего), правит админ в «Настройках», в коде не хардкодить
- `border-radius: .4rem` для всех `form-control` и `form-select`
- Фильтры скрыты по умолчанию, состояние в `localStorage`
- Компонент заголовка: `<x-page-header>`, флеш-алерты: `@include('partials.alerts')`
- **Кнопка «Назад»** — `backUrl` у `<x-page-header>` задаёт только родителя по умолчанию
  (форма → карточка, карточка → список). Куда вернуться на самом деле, решает
  `resources/js/back-link.js` по истории вкладки: откуда пришли, с фильтрами. Адрес возврата в
  контроллерах не вычислять (`url()->previous()` после сохранения указывает на саму страницу или
  на форму). «Отмена» и свои кнопки возврата — ссылка с `data-back` (подпись — в `[data-back-text]`).
  Формы, на которые не возвращаются, определяет `App\Support\BackNavigation` по имени маршрута
  (`*.create`, `*.edit`, `*.form` …) — новую форму называй так же
- Нельзя вкладывать `<form>` внутрь `<form>` — AJAX через `fetch` с `data-action`
- **Формы, изменяющие данные, помечаются `data-submit-guard`** — `resources/js/form-submit-guard.js` (подключён глобально через `app.js`) на время сохранения блокирует submit-кнопки, ставит спиннер «Сохранение…» на нажатую кнопку и восстанавливает всё при возврате по «Назад» (bfcache). Новые формы обязаны использовать этот атрибут, а не свою инлайновую блокировку; своя подпись спиннера — значением: `data-submit-guard="Выполняется…"`. Без атрибута — только вход, выход и формы, которые скрипт отправляет через `form.submit()` (событие submit не возникает) — список `GUARD_EXEMPT` в `tests/Unit/FormSubmitGuardTest.php`, тест проверяет все шаблоны. Кнопки должны быть `button[type="submit"]` (в т.ч. с `name`/`value` — хелпер блокирует их отложенно, чтобы значение сабмиттера попало в запрос); confirm-подтверждения — атрибутом формы `data-confirm="текст"` (данные подставлять только так: `{{ }}` внутри `on*=` браузер раскодирует и выполнит как код) или `e.preventDefault()` в click-обработчике кнопки
- **Единицы измерения** — только из карточки товара (`products.uom`) через `App\Support\Uom`
  (в JS — `window.Uom`, `resources/js/uom.js`). «м²/м³» в шаблоны и скрипты не вписывать; итог по
  разным товарам — `Uom::formatTotals()` (раздельно по единицам)
- `ProductPicker` (`resources/js/product-picker.js`) — выбор товара с SKU-фильтрацией
- `WorkerPicker` (`resources/js/worker-picker.js`) — выбор работника по аналогии с `ProductPicker`
- Перед созданием шаблона — проверяй `resources/views/partials/`, используй `@include`, не дублируй

---

## Обязательные .env

- `MOYSKLAD_TOKEN` — токен API
- `DEFAULT_STORE_ID` — UUID склада для корректировок партий (`config('app.default_store_id')`)
- `DEFAULT_STORE_CODE` — внешний код склада по умолчанию (`Store::getDefault()`)
- `ADMIN_PASSWORD` / `ADMIN_PHONE` — первый администратор (в production пароль обязателен)
- Статусы техопераций («в работе», «завершена») — не env, а настройки в админке
  (`Setting` `MOYSKLAD_IN_WORK_STATE` / `MOYSKLAD_DONE_STATE`)
- `env()` — только в `config/`: после `config:cache` вне конфигов он отдаёт null
