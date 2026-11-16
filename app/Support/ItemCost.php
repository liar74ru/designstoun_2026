<?php

namespace App\Support;

use App\Models\DepartmentModifier;
use App\Models\Product;
use App\Models\ProductionItemModifier;
use App\Models\StoneReceptionItem;
use Illuminate\Support\Collection;

/**
 * Расчёт стоимостных атрибутов позиции документа по правилам отдела.
 *
 * Единая точка для приёмки и цеха: раньше один и тот же расчёт был выписан
 * в семи местах двух сервисов, и копии разошлись — в цехе, например, забыли
 * передать SKU, из-за чего бонус плитки-маски там не применялся.
 *
 * Возвращает и атрибуты позиции, и набор сработавших правил: правила нужно
 * записать снапшотом, чтобы правка настроек задним числом не переписывала
 * выплаченные зарплаты.
 */
class ItemCost
{
    /**
     * @param  string[]  $manualKeys Ключи ручных правил, отмеченных в форме
     * @param  ?string   $batchSku   SKU партии сырья — для правил с условием
     *                               доступности; null, если контекста партии нет
     * @param  string[]  $disabledKeys Ключи sku-правил, снятых у позиции
     * @return array{attributes: array, modifiers: Collection<int, DepartmentModifier>}
     */
    public static function compute(
        ?Product $product,
        ?int $departmentId,
        string $scope,
        array $manualKeys = [],
        ?string $batchSku = null,
        array $disabledKeys = [],
    ): array {
        $applied = ModifierEngine::resolve(
            $departmentId,
            $scope,
            $product?->sku,
            $manualKeys,
            $batchSku,
            $disabledKeys,
        );

        return self::computeWithRules(
            $product,
            (float) ($product?->prod_cost_coeff ?? 0),
            (float) ($product?->master_cost_coeff ?? 0),
            $departmentId,
            $applied,
            $disabledKeys,
        );
    }

    /**
     * Расчёт от готового набора правил: текущих правил отдела, снапшота позиции или их смеси
     * (ModifierEngine::resolveKeepingSnapshot). Обе базы задаёт вызывающий.
     *
     * @template TRule of DepartmentModifier|ProductionItemModifier
     *
     * @param  Collection<int, TRule>  $applied
     * @param  string[]  $disabledKeys Ключи sku-правил, снятых у позиции
     * @return array{attributes: array, modifiers: Collection<int, TRule>}
     */
    public static function computeWithRules(
        ?Product $product,
        float $baseCoeff,
        float $masterBaseCoeff,
        ?int $departmentId,
        Collection $applied,
        array $disabledKeys = [],
    ): array {
        $effCoeff    = ModifierEngine::workerCoeff($baseCoeff, $applied);
        $masterCoeff = ModifierEngine::masterCoeff($masterBaseCoeff, $applied);

        return [
            'attributes' => [
                'base_cost_coeff'             => $baseCoeff,
                'effective_cost_coeff'        => $effCoeff,
                'master_base_cost_coeff'      => $masterBaseCoeff,
                'master_effective_cost_coeff' => $masterCoeff,
                'is_small_tile'        => StoneReceptionItem::skuIsSmallTile($product?->sku),
                'worker_cost_per_m2'   => $product?->prodCost($effCoeff, $departmentId),
                'master_cost_per_m2'   => $product
                    ? RateFormula::stepped(
                        DepartmentSettings::masterBaseRate($departmentId),
                        $masterCoeff,
                        DepartmentSettings::masterRoundStep($departmentId),
                    )
                    : null,
                'disabled_modifier_keys' => $disabledKeys ?: null,
            ],
            'modifiers' => $applied,
        ];
    }

    /**
     * Пересчёт от заданной вручную базы — для формы правки коэффициента.
     * База берётся из формы как есть и правилами не переопределяется, поэтому
     * повторное сохранение не сдвигает коэффициент.
     *
     * $masterBaseCoeff — база мастера из формы; null — берётся из продукта.
     */
    public static function computeFromBase(
        ?Product $product,
        float $baseCoeff,
        ?int $departmentId,
        string $scope,
        array $manualKeys = [],
        ?string $batchSku = null,
        ?float $masterBaseCoeff = null,
        array $disabledKeys = [],
    ): array {
        $applied = ModifierEngine::resolve($departmentId, $scope, $product?->sku, $manualKeys, $batchSku, $disabledKeys);

        return self::computeWithRules(
            $product,
            $baseCoeff,
            $masterBaseCoeff ?? (float) ($product?->master_cost_coeff ?? 0),
            $departmentId,
            $applied,
            $disabledKeys,
        );
    }

    /**
     * Записать снапшот применённых правил, заменив прежний.
     *
     * Правила могут быть и строками прежнего снапшота (сохранённое правило, выключенное
     * в отделе): их атрибуты снимаются до удаления строк.
     *
     * @template TRule of DepartmentModifier|ProductionItemModifier
     *
     * @param  Collection<int, TRule> $modifiers
     */
    public static function syncSnapshot(string $foreignKey, int $itemId, Collection $modifiers): void
    {
        $rows = $modifiers->map(fn ($modifier) => ProductionItemModifier::attributesFrom($modifier))->all();

        ProductionItemModifier::where($foreignKey, $itemId)->delete();

        foreach ($rows as $row) {
            ProductionItemModifier::create(array_merge($row, [$foreignKey => $itemId]));
        }
    }
}
