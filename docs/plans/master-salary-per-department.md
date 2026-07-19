# План: зарплата мастера — расчёт по отделам (каркас стратегий)

> **Статус:** запланировано, реализация отложена. Дата составления: 2026-07-15.

## Контекст

Сейчас зарплата мастера считается одинаково для всех отделов: формула зашита в `StoneReceptionItem::computeMasterCost()` (`app/Models/StoneReceptionItem.php:127-132`) — `MASTER_BASE_RATE + надбавка за подрез + надбавка за мелкую плитку`, ставки — глобальные записи в таблице `settings`. По факту логика расчёта в отделах разная (формулы будут принципиально отличаться, конкретика появится позже).

Важно: `master_cost_per_m2` **сохраняется в позицию** (`stone_reception_items`, `packaging_items`) в момент создания/редактирования, а дашборд мастера и **себестоимость в МойСклад** (`StoneReceptionSyncService.php:391, 546`) читают уже сохранённое поле. Поэтому per-department логика внедряется в точке записи — оба потребителя получат правильные значения автоматически, код синхронизации МойСклад менять не нужно.

Решения заказчика: формулы разные по отделам; настройка — админом через UI; распространяется и на приёмку, и на упаковку; конкретных новых формул пока нет — нужен расширяемый каркас, текущая формула становится первой стратегией.

## Архитектура

**Паттерн «стратегия»**: именованные формулы-калькуляторы в коде, админ на странице отдела выбирает стратегию и задаёт её параметры. Отдел без настройки → стратегия по умолчанию с глобальными ставками (поведение byte-for-byte как сейчас).

### 1. Миграция + модель Department

`database/migrations/2026_XX_XX_000001_add_master_salary_strategy_to_departments.php`:
- `departments.master_salary_strategy` — string(50), nullable
- `departments.master_salary_config` — jsonb, nullable

В `Department`: добавить в `$fillable`, каст `master_salary_config => array`. Без backfill — `null` = «глобальные настройки» (исторические значения не пересчитываются).

*Почему не `department_operation_settings`: та таблица моделирует права позиций на операции UI (каталог `config/department_operations.php`, валидация по `Worker::POSITIONS`); отношение стратегии к отделу 1:1 — прямые колонки проще и без join.*

### 2. Каталог стратегий — `config/master_salary_strategies.php`

По образцу `config/department_operations.php`:

```php
return [
    'flag_rates' => [
        'label' => 'Ставки по флагам (базовая)',
        'class' => \App\Services\Salary\Strategies\FlagRatesStrategy::class,
        'params' => [
            'base_rate'       => ['label' => 'Базовая ставка, ₽/м²',       'setting_key' => 'MASTER_BASE_RATE',       'default' => 100],
            'undercut_rate'   => ['label' => 'Надбавка за подкол',          'setting_key' => 'MASTER_UNDERCUT_RATE',   'default' => 50],
            'small_tile_rate' => ['label' => 'Надбавка за плитку < 50 мм', 'setting_key' => 'MASTER_SMALL_TILE_RATE', 'default' => 50],
        ],
        'default' => true,
    ],
];
```

Admin-UI и блок ставок дашборда рендерят поля параметров генерически из `params`. Новая формула в будущем = новый класс + запись в каталоге.

### 3. Классы — `app/Services/Salary/`

- **`MasterSalaryContext`** — `final readonly` DTO: `isUndercut`, `isSmallTile`, `?sku`, `?Product $product`, `?float $quantity`. Будущие стратегии получают всё нужное без изменения интерфейса.
- **`MasterSalaryStrategy`** (интерфейс): `costPerM2(MasterSalaryContext $context, array $config): float`. Возврат — **ставка за м²**: хранимое поле, дашборд и МойСклад не меняются по форме; не-м²-формула нормализует внутри себя (quantity есть в контексте). В докблоке: результат округляется до 2 знаков при сохранении (`decimal:2`).
- **`Strategies/FlagRatesStrategy`** — текущая формула, параметры читает из `$config`.
- **`MasterSalaryResolver`** — единая точка:
  - `resolve(?int $departmentId): array` — `['key', 'strategy', 'config']`; цепочка: отдел null / стратегия null / неизвестный ключ → стратегия по умолчанию (неизвестный ключ — `Log::warning`, не исключение: запись идёт в транзакции приёмки). Параметры: `master_salary_config[param]` ?? `Setting::get(setting_key, default)` — частичная настройка переопределяет только заполненные поля.
  - `costPerM2(?int $departmentId, MasterSalaryContext $ctx): float`
  - `saveForDepartment(Department $d, ?string $strategyKey, array $config): void` + сброс кэша
  - Кэш: `Cache::remember("dept.{$id}.master_salary", 300, ...)` (по образцу `Department::positionAllowedFor`, `app/Models/Department.php:98-119`) + in-memory memo на запрос (циклы по позициям).

### 4. Точки записи (8 мест, механическая замена)

Внедрить `MasterSalaryResolver` через конструктор; заменить `StoneReceptionItem::computeMasterCost($isUndercut, $isSmallTile)` на `$this->salaryResolver->costPerM2($reception->department_id, new MasterSalaryContext(...))`:

- `app/Services/StoneReceptionService.php:490, 518, 611, 651` — `$reception->department_id`
- `app/Services/PackagingService.php:340, 367, 433, 474` — `$packaging->department_id`

Во всех 8 местах родительская модель уже в scope (проверено). `department_id` может быть `null` (работник без отдела) → резолвер принимает `?int`, фолбэк на глобальные ставки.

`StoneReceptionItem::computeMasterCost()` — **оставить с телом как есть, пометить `@deprecated`**: его вызывает уже выкаченная миграция `2026_04_22_000002_add_master_fields_to_stone_reception_items.php:21` (backfill при `migrate:fresh`); файл миграции не трогать.

### 5. Admin UI — страница отдела

- Роут в группе `can:manage-admin` рядом с `routes/web.php:178`:
  `Route::patch('/departments/{department}/master-salary', [DepartmentController::class, 'updateMasterSalary'])->name('departments.master-salary.update');`
- `DepartmentController::show()` — передать в view каталог стратегий + текущие настройки отдела + эффективные fallback-значения (через `resolve()`) для placeholder'ов.
- `DepartmentController::updateMasterSalary()` — валидация: `strategy` `nullable|in:<ключи каталога>` (пусто = «по умолчанию»); `config.*` `nullable|numeric|min:0`, фильтр по ключам параметров выбранной стратегии; пустые инпуты не сохраняются (→ fallback). Персист через `MasterSalaryResolver::saveForDepartment()`, redirect back + flash.
- View `resources/views/admin/departments/show.blade.php`: новая карточка «Зарплата мастера» после «Склады по умолчанию», в стиле существующих карточек-форм: select стратегии (первая опция «— по умолчанию (глобальные настройки) —») + блок `div[data-strategy]` на стратегию с number-инпутами параметров (placeholder = эффективное глобальное значение, подсказка «пусто — используется глобальная настройка»), ~10 строк inline-JS для переключения блоков.

### 6. Дашборд — блок ставок

- `WorkerDashboardService::getDashboardData()` — принимать `Worker $worker` вместо `int $workerId` (единственный вызов: `CutterWorkerDashboardController.php:48`, `$worker` там уже есть).
- Массив `rates` (l.74-79) строить генерически из `resolve($worker->department_id)`: `[label, value]` по параметрам стратегии + одна глобальная строка `MASTER_PACKAGING_RATE` (паритет отображения; в формуле её нет и не было — рудимент).
- View `resources/views/workers/dashboard/show.blade.php:85-88` — цикл вместо 4 фиксированных строк + подсказка «ставки вашего отдела на текущий момент» (агрегат за период считается по сохранённым значениям позиций).

## Тесты (Pest)

Новая директория `tests/Feature/Salary/`:
- `MasterSalaryResolverTest` — дефолт при null-отделе; отдел без настройки = глобальные Settings; частичное переопределение; неизвестный ключ стратегии → фолбэк; сброс кэша после `saveForDepartment`.
- `MasterSalaryComputationTest` — приёмка и упаковка через сервисы в настроенном отделе пишут переопределённый `master_cost_per_m2`; в ненастроенном — прежние значения (регрессионный гард «нулевого изменения поведения»); `updateItemCoeff` обоих модулей учитывает настройку отдела.

Существующие:
- `tests/Feature/Department/` — новый `DepartmentMasterSalaryTest`: PATCH-эндпоинт (только админ, валидация, персист, пустые параметры → fallback), по образцу `DepartmentOperationsTest`.
- `tests/Feature/MasterDashboard/` — структура генерического `rates`.
- `tests/Feature/Moysklad/` — прогнать как есть (читают хранимую колонку) + один кейс: итог себестоимости отражает переопределённую ставку отдела.

## Порядок реализации

1. Миграция + поля/каст `Department`
2. `config/master_salary_strategies.php`
3. `app/Services/Salary/` (Context, интерфейс, FlagRatesStrategy, Resolver)
4. Замена 8 точек записи + `@deprecated` на `computeMasterCost`
5. Роут + `DepartmentController::updateMasterSalary` + `show()` + карточка в view
6. `WorkerDashboardService` + view дашборда
7. Тесты, `make test`

## Верификация

1. `php artisan migrate:fresh --seed` на тестовой БД — миграция `2026_04_22_000002` (вызывает deprecated-статик) работает.
2. `make test` — полный прогон; существующие тесты StoneReception/Packaging/Moysklad/MasterDashboard = гард отсутствия изменений поведения.
3. Вручную: отдел без настройки → приёмка → `master_cost_per_m2` = 100/150/200 как сейчас; задать override в `admin/departments/{id}` → новая приёмка использует его; старые позиции не тронуты; сумма в МойСклад отражает только новые/отредактированные позиции.
4. `grep -rn "computeMasterCost" app/` → остаётся только deprecated-определение в модели.

## Риски / о чём помнить

- **`refreshItemCoeffs`/`updateItemCoeff`** — это точки пересчёта существующих записей по явному действию пользователя; после изменения они пересчитают `master_cost_per_m2` по текущей стратегии отдела (аналогично сегодняшнему поведению при смене глобальных ставок). Осознанно, не «чинить» молча.
- **`department_id = null`** (упаковки/приёмки работников без отдела) → глобальный фолбэк; переопределения отдела не применятся молча.
- Смена ставок/стратегии не трогает историю — только новые и явно пересчитанные позиции (требование заказчика).
