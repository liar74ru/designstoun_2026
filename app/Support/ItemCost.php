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
     * @return array{attributes: array, modifiers: Collection<int, DepartmentModifier>}
     */
    public static function compute(
        ?Product $product,
        ?int $departmentId,
        string $scope,
        array $manualKeys = [],
        ?string $batchSku = null,
    ): array {
        $baseCoeff = (float) ($product?->prod_cost_coeff ?? 0);

        $applied = ModifierEngine::resolve(
            $departmentId,
            $scope,
            $product?->sku,
            $manualKeys,
            $batchSku,
        );

        $effCoeff    = ModifierEngine::workerCoeff($baseCoeff, $applied);
        $masterCoeff = ModifierEngine::masterCoeff(
            (float) ($product?->master_cost_coeff ?? 0),
            $applied,
        );

        return [
            'attributes' => [
                'base_cost_coeff'             => $baseCoeff,
                'effective_cost_coeff'        => $effCoeff,
                'master_base_cost_coeff'      => (float) ($product?->master_cost_coeff ?? 0),
                'master_effective_cost_coeff' => $masterCoeff,
                // Старые булевы колонки продолжают писаться по прежним правилам —
                // на них ещё завязаны формы, группировки и бейджи (этапы 4–5).
                'is_undercut'          => in_array('undercut', $manualKeys, true),
                'is_edging'            => in_array('edging', $manualKeys, true),
                'is_small_tile'        => StoneReceptionItem::skuIsSmallTile($product?->sku),
                'worker_cost_per_m2'   => $product?->prodCost($effCoeff, $departmentId),
                'master_cost_per_m2'   => $product
                    ? RateFormula::stepped(DepartmentSettings::masterBaseRate($departmentId), $masterCoeff)
                    : null,
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
    ): array {
        $result = self::compute($product, $departmentId, $scope, $manualKeys, $batchSku);

        $applied  = $result['modifiers'];
        $effCoeff = ModifierEngine::workerCoeff($baseCoeff, $applied);

        $result['attributes']['base_cost_coeff']      = $baseCoeff;
        $result['attributes']['effective_cost_coeff'] = $effCoeff;
        $result['attributes']['worker_cost_per_m2']   = $product?->prodCost($effCoeff, $departmentId);

        if ($masterBaseCoeff !== null) {
            $masterCoeff = ModifierEngine::masterCoeff($masterBaseCoeff, $applied);

            $result['attributes']['master_base_cost_coeff']      = $masterBaseCoeff;
            $result['attributes']['master_effective_cost_coeff'] = $masterCoeff;
            $result['attributes']['master_cost_per_m2']          = $product
                ? RateFormula::stepped(DepartmentSettings::masterBaseRate($departmentId), $masterCoeff)
                : null;
        }

        return $result;
    }

    /**
     * Записать снапшот применённых правил, заменив прежний.
     *
     * @param  Collection<int, DepartmentModifier> $modifiers
     */
    public static function syncSnapshot(string $foreignKey, int $itemId, Collection $modifiers): void
    {
        ProductionItemModifier::where($foreignKey, $itemId)->delete();

        foreach ($modifiers as $modifier) {
            ProductionItemModifier::create(array_merge(
                ProductionItemModifier::attributesFrom($modifier),
                [$foreignKey => $itemId],
            ));
        }
    }
}
