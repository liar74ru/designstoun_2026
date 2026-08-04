# Формирование processingSum для МойСклад

`processingSum` — стоимость производства за единицу объёма, передаётся в МойСклад при создании/обновлении техоперации. МойСклад хранит это значение в **копейках** и умножает на `quantity` самостоятельно.

Реализация: `app/Services/Moysklad/StoneReceptionSyncService.php`

---

## Контекст

Формула описана для техоперации **производства плитки** (`StoneReception`, модель `App\Models\StoneReception`).

---

## Составляющие

### 1. Накладные расходы на единицу (`manualCostPerUnit(?int $departmentId)`)

Метод возвращает сумму строк таблицы `department_expenses` **отдела документа** через
`App\Support\DepartmentSettings::overheadPerUnit()`.

Состав расходов у каждого отдела **свой**: произвольный набор строк `имя` + `сумма (₽/м²)`,
который админ ведёт в карточке отдела (`/admin/departments/{id}` → «Накладные расходы отдела»).
У карьера может не быть аренды цеха, у упаковки — расхода пилы; количество строк тоже разное.

**Наследования из глобальных настроек нет** — расходы существуют только на уровне отдела.
Поэтому отдел документа определяется цепочкой `StoneReception::effectiveDepartmentId()`:
`свой department_id → отдел партии сырья → отдел пильщика`. Если отдел не определился —
накладные равны 0.

Кэш — `dept.{id}.expenses` (TTL 86400), сбрасывается через `Department::forgetSettingsCache()`.

### 2. Зарплаты работников (`workerSalaryTotal`)

Считается как сумма по позициям приёмки:

```
workerSalaryTotal = Σ (item->effectiveProdCost() × item->quantity)
```

`effectiveProdCost()` — эффективная стоимость производства единицы позиции (с учётом коэффициентов работника).

### 3. Зарплата мастера (`masterSalaryTotal`)

```
masterSalaryTotal = Σ (item->master_cost_per_m2 × item->quantity)
```

`master_cost_per_m2` фиксируется в момент создания позиции приёмки через
`StoneReceptionItem::computeMasterCost(isUndercut, departmentId, product)`:

```
base            = MASTER_BASE_RATE отдела (фолбэк — глобальная, default 100)
masterCostPerM2 = ОКРУГЛВНИЗ((base + base×17%×product.master_cost_coeff) / 10) × 10
                + (isUndercut ? MASTER_UNDERCUT_RATE отдела : 0)
```

- `master_cost_coeff` — атрибут `masterCostCoeff` продукта из МойСклад. Атрибут не заведён
  или не заполнен → `0` → ставка равна базовой. Так надбавки, зависящие от SKU
  (например, мелкая плитка), задаются точечно на продукте.
- Надбавка за подкол — флаг-чекбокс, не зависит от SKU, поэтому прибавляется **после**
  округления по 10.
- Формула — общая с зарплатой пильщика: `App\Support\RateFormula::stepped()`.
- Флаг `is_small_tile` на ставку больше не влияет и остаётся только UI-индикатором.

### 4. Перевод в копейки на единицу (`calcProcessingSum`)

```
processingSum = round(totalRubles × 100 / totalQty)
```

---

## Формула по контексту вызова

| Метод | `totalRubles` | Зарплата пильщика | Зарплата мастера |
|---|---|---|---|
| `createProcessingForBatch` | `workerSalaryTotal + masterSalaryTotal + накладные × totalQty` | Да | Да |
| `updateProcessingProducts` | `workerSalaryTotal + masterSalaryTotal + накладные × totalQty` | Да | Да |

Накладные в обоих случаях считаются по `effectiveDepartmentId()` приёмки.

> `raw_material_batches.processing_sum` — legacy-снапшот накладных на уровне партии.
> Он продолжает обновляться, но ни на техоперацию, ни на отображение больше не влияет:
> блок «Расходы производства» на странице приёмки считает накладные по отделу приёмки,
> тем же правилом, что уходит в МойСклад.

---

## Цех (`WorkshopSyncService`)

Для техоперации **цеха** (`Workshop`) формула отличается:

- Накладных нет: в расчёт входит только зарплата.
- Если у операции задан `manual_processing_sum` — он используется напрямую (рубли → копейки).
- Иначе `workerSalaryTotal = Σ (WorkshopItem::effectiveProdCost() × quantity)` по строкам с ролью
  «продукт», где `effectiveProdCost()` возвращает зафиксированный при создании строки
  `worker_cost_per_m2` (расчёт — `Product::prodCost()` от `PIECE_RATE` и `prod_cost_coeff`).
- Итог: `processingSum = round(workerSalaryTotal × 100 / totalQuantity)`.

Зарплата мастера в техоперацию цеха не входит, хотя `master_cost_per_m2` в строках фиксируется
(используется в дашбордах).

### Товар-результат (`result_product_id`)

У упаковки два режима формирования позиций техоперации:

| Режим | `products` (приход) | `materials` (списание) | `quantity` / делитель `processingSum` |
|---|---|---|---|
| `result_product_id = NULL` (цех) | упакованные продукты (те же SKU) | продукты + тара | Σ количеств позиций |
| `result_product_id` задан (отдел упаковки) | товар-результат, кол-во = `package_quantity` | продукты + тара | `package_quantity` |

Зарплата работника в обоих режимах считается одинаково — по позициям операции (`WorkshopItem`). В режиме товара-результата делителем `calcProcessingSum` становится `package_quantity`, чтобы МойСклад, умножив копейки/ед. на `quantity` техоперации, получил ту же итоговую сумму.

После успешной синхронизации остатки затронутых товаров (продукты, тара, товар-результат) подтягиваются из МойСклад в `product_stocks` (`StockSyncService::updateProductStocksByMoyskladId`).
