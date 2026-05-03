# Формирование processingSum для МойСклад

`processingSum` — стоимость производства за единицу объёма, передаётся в МойСклад при создании/обновлении техоперации. МойСклад хранит это значение в **копейках** и умножает на `quantity` самостоятельно.

Реализация: `app/Services/Moysklad/StoneReceptionSyncService.php`

---

## Контекст

Формула описана для техоперации **производства плитки** (`StoneReception`, модель `App\Models\StoneReception`).

---

## Составляющие

### 1. Накладные расходы на единицу (`manualCostPerUnit`)

Метод суммирует статьи из таблицы `settings` (рублей на единицу продукции):

| Ключ настройки | Статья |
|---|---|
| `BLADE_WEAR` | Износ диска |
| `RECEPTION_COST` | Стоимость приёмки |
| `PACKAGING_COST` | Упаковка |
| `WASTE_REMOVAL` | Вывоз отходов |
| `ELECTRICITY` | Электроэнергия |
| `PPE_COST` | СИЗ |
| `FORKLIFT_COST` | Погрузчик |
| `MACHINE_COST` | Станок |
| `RENT_COST` | Аренда |
| `OTHER_COSTS` | Прочие расходы |

Результат хранится в `$this->processingSum` при инициализации сервиса.

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

`master_cost_per_m2` фиксируется в момент создания позиции приёмки через `StoneReceptionItem::computeMasterCost(isUndercut, isSmallTile)`:

```
masterCostPerM2 = MASTER_BASE_RATE
                + (isUndercut  ? MASTER_UNDERCUT_RATE   : 0)
                + (isSmallTile ? MASTER_SMALL_TILE_RATE : 0)
```

> `RECEPTION_COST` остаётся в накладных — он покрывает ту часть приёмки, которая пока не перенесена в точный подсчёт.

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

---

## Упаковка (`PackagingSyncService`)

Для техоперации **упаковки** (`Packaging`) формула отличается:

- Накладных нет: `manualCostPerUnit = 0`.
- `workerSalaryTotal = Σ (PackagingItem::effectiveProdCost × quantity)`, где `effectiveProdCost` — заранее вычисленный `worker_cost_per_m2` упаковщика.
- `worker_cost_per_m2` рассчитывается через `PackagingItem::computePackerCost($productCoeff, $packageCoeff)`:
  ```
  packerCostPerM2 = PACKAGING_PROD_COST × product.prod_cost_coeff
                  + PACKAGING_COST      × packageProduct.prod_cost_coeff
  ```
  - `PACKAGING_PROD_COST` — ставка за упаковываемый продукт (SKU `04-XX`), default 0.
  - `PACKAGING_COST` — ставка за тару (SKU `07-03-XX`).
  - Коэффициенты — поле `Product.prod_cost_coeff` для каждого товара.
- Итог: `processingSum = round(workerSalaryTotal × 100 / totalQuantity)`.

`PACKAGING_COST` уже учтён в зарплате упаковщика — отдельно как накладные он **не складывается**.
