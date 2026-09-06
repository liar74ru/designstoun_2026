<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Ключи настроек приложения:
 *
 * Коэффициенты-модификаторы (глобальные, реестр — config/production_rates.php,
 * читать только через App\Support\ProductionRates):
 *   UNDERCUT_PENALTY       — штраф коэффициента при флаге «подкол > 80%»
 *   EDGING_COEFF           — коэффициент «Торцовка»: полностью заменяет prod_cost_coeff (доступен для партий 04-XX); может быть отрицательным
 *   MASK_TILE_COEFF_BONUS  — бонус коэффициента для SKU плитки-маски (04-07-XX)
 *
 * Накладные расходы здесь НЕ хранятся: у каждого отдела свой произвольный
 * набор строк в таблице `department_expenses` (см. App\Support\DepartmentSettings).
 *
 * Базовые ставки — значения по умолчанию, переопределяются в отделе
 * (реестр — config/department_settings.php, читать через App\Support\DepartmentSettings):
 *   PIECE_RATE             — базовая ставка пильщика (₽/ед)
 *   MASTER_BASE_RATE       — базовая ставка мастера за м² (масштабируется products.master_cost_coeff)
 *   MASTER_UNDERCUT_RATE   — надбавка мастеру за подкол > 80%
 *
 * МойСклад (строки):
 *   MOYSKLAD_IN_WORK_STATE — имя статуса «в работе» техоперации
 *   MOYSKLAD_DONE_STATE    — имя завершающего статуса техоперации
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'label', 'description'];

    private const CACHE_TTL = 86400;
    private const CACHE_PREFIX = 'setting.';

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            self::CACHE_PREFIX . $key,
            self::CACHE_TTL,
            fn () => static::where('key', $key)->value('value') ?? $default
        );
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_PREFIX . $key);
    }

    /**
     * Получить склад для сырья отдела из уже загруженной коллекции складов.
     */
    public static function deptRawStore(Department $dept, Collection $stores): ?Store
    {
        return $dept->default_raw_store_id
            ? $stores->firstWhere('id', $dept->default_raw_store_id)
            : null;
    }

    /**
     * Получить склад готовой продукции отдела из уже загруженной коллекции складов.
     */
    public static function deptProductStore(Department $dept, Collection $stores): ?Store
    {
        return $dept->default_product_store_id
            ? $stores->firstWhere('id', $dept->default_product_store_id)
            : null;
    }

    /**
     * Получить склад производства/цеха отдела из уже загруженной коллекции складов.
     */
    public static function deptProductionStore(Department $dept, Collection $stores): ?Store
    {
        return $dept->default_production_store_id
            ? $stores->firstWhere('id', $dept->default_production_store_id)
            : null;
    }

    /**
     * Обновить три склада по умолчанию для отдела.
     */
    public static function setDeptStores(
        Department $dept,
        ?string $rawStoreId,
        ?string $productStoreId,
        ?string $productionStoreId
    ): void {
        $dept->update([
            'default_raw_store_id'        => $rawStoreId ?: null,
            'default_product_store_id'    => $productStoreId ?: null,
            'default_production_store_id' => $productionStoreId ?: null,
        ]);
    }
}
