<?php

namespace App\Models;

use App\Support\DepartmentSettings;
use App\Support\ProductionRates;
use App\Support\RateFormula;
use Illuminate\Database\Eloquent\Model;

class StoneReceptionItem extends Model
{
    protected $table = 'stone_reception_items';

    protected $fillable = [
        'stone_reception_id',
        'product_id',
        'quantity',
        'base_cost_coeff',
        'effective_cost_coeff',
        'master_base_cost_coeff',
        'master_effective_cost_coeff',
        'is_undercut',
        'is_edging',
        'is_small_tile',
        'worker_cost_per_m2',
        'master_cost_per_m2',
    ];

    protected $casts = [
        'quantity'                    => 'decimal:3',
        'base_cost_coeff'             => 'decimal:4',
        'effective_cost_coeff'        => 'decimal:4',
        'master_base_cost_coeff'      => 'decimal:4',
        'master_effective_cost_coeff' => 'decimal:4',
        'is_undercut'                 => 'boolean',
        'is_edging'                   => 'boolean',
        'is_small_tile'               => 'boolean',
        'worker_cost_per_m2'          => 'decimal:2',
        'master_cost_per_m2'          => 'decimal:2',
    ];

    // ─── Связи ───────────────────────────────────────────────────────────────

    /**
     * Приёмка
     */
    public function reception()
    {
        return $this->belongsTo(StoneReception::class, 'stone_reception_id');
    }

    /**
     * Продукт
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // ─── Бизнес-логика ───────────────────────────────────────────────────────

    /** Правила, применённые к позиции (снапшот на момент создания). */
    public function modifiers()
    {
        return $this->hasMany(ProductionItemModifier::class, 'stone_reception_item_id')
            ->orderBy('id');
    }

    /**
     * Базовый коэффициент позиции — простое чтение колонки.
     *
     * Раньше он восстанавливался обратным счётом из effective_cost_coeff, и
     * бонус плитки-маски при этом не снимался: форма правки коэффициента
     * отдавала значение с уже учтённым бонусом, сервер применял его повторно,
     * и коэффициент рос на 2 при каждом сохранении. Теперь база хранится явно.
     *
     * Фолбэк на справочник продукта — для позиций, созданных до появления
     * колонки, если бэкфил по ним не отработал.
     */
    public function getBaseCoeffAttribute(): float
    {
        if ($this->base_cost_coeff !== null) {
            return (float) $this->base_cost_coeff;
        }

        return (float) ($this->product?->prod_cost_coeff ?? 0);
    }

    /**
     * Рассчитать итоговый коэффициент из базового и набора флагов-модификаторов.
     * is_edging  — полная замена baseCoeff на EDGING_COEFF (для партий 04-XX).
     * is_undercut — вычитает UNDERCUT_PENALTY (применяется поверх торцовки).
     * SKU-бонус маски (04-07-xx) — добавляется к baseCoeff перед торцовкой/подколом.
     */
    public static function computeEffectiveCoeff(float $baseCoeff, bool $isUndercut, bool $isEdging = false, ?string $sku = null): float
    {
        $coeff = $isEdging
            ? ProductionRates::edgingCoeff()
            : $baseCoeff + (self::skuIsMaskTile($sku) ? ProductionRates::maskTileBonus() : 0.0);
        if ($isUndercut) {
            $coeff -= ProductionRates::undercutPenalty();
        }
        return $coeff;
    }

    /**
     * Стоимость единицы продукции для этой позиции,
     * рассчитанная по зафиксированному effective_cost_coeff.
     *
     * Фолбэк считается по ставке отдела приёмки: у позиций, созданных до
     * появления снапшота, отдела в самой позиции нет.
     */
    public function effectiveProdCost(): float
    {
        return $this->worker_cost_per_m2 !== null
            ? (float) $this->worker_cost_per_m2
            : $this->product->prodCost(
                (float) $this->effective_cost_coeff,
                $this->reception?->effectiveDepartmentId()
            );
    }

    /**
     * Зарплата пильщика за данную позицию.
     */
    public function calculateWorkerPay(): float
    {
        return (float) $this->quantity * $this->effectiveProdCost();
    }

    /**
     * Проверяет, является ли SKU плиткой < 50мм (маска 04-хх-30).
     *
     * Флаг is_small_tile — только UI-индикатор и признак группировки в сводках.
     * На master_cost_per_m2 он больше не влияет: надбавка за мелкую плитку
     * кодируется через products.master_cost_coeff конкретного SKU.
     */
    public static function skuIsSmallTile(?string $sku): bool
    {
        if (!$sku) return false;
        $parts = explode('-', $sku);
        return count($parts) >= 3 && $parts[2] === '30';
    }

    /**
     * Проверяет, является ли SKU плиткой маской (04-07-xx).
     */
    public static function skuIsMaskTile(?string $sku): bool
    {
        if (!$sku) return false;
        $parts = explode('-', $sku);
        return ($parts[0] ?? '') === '04' && ($parts[1] ?? '') === '07';
    }

    /**
     * Ставка мастера за м².
     *
     * Базовая ставка отдела масштабируется коэффициентом продукта
     * (атрибут masterCostCoeff МойСклад) по той же ступенчатой формуле,
     * что и зарплата пильщика: ОКРУГЛВНИЗ((ставка + ставка×17%×коэф)/10)×10.
     * Коэффициент 0 (в т.ч. когда атрибут не заполнен) → базовая ставка.
     *
     * Надбавка за подкол — флаг-чекбокс, не зависит от SKU,
     * поэтому прибавляется поверх, уже ПОСЛЕ округления по 10.
     *
     * @param int|null $departmentId Отдел документа; null → глобальные настройки
     */
    public static function computeMasterCost(
        bool $isUndercut,
        ?int $departmentId = null,
        ?Product $product = null
    ): float {
        $rate = RateFormula::stepped(
            DepartmentSettings::masterBaseRate($departmentId),
            (float) ($product?->master_cost_coeff ?? 0)
        );

        return $isUndercut
            ? $rate + DepartmentSettings::masterUndercutRate($departmentId)
            : $rate;
    }

    /**
     * Зарплата мастера за данную позицию.
     */
    public function calculateMasterPay(): float
    {
        return (float) $this->quantity * (float) ($this->master_cost_per_m2 ?? 0);
    }
}
