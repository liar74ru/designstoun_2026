<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StoneReception;
use App\Models\StoneReceptionItem;
use App\Models\Workshop;
use App\Models\WorkshopItem;
use App\Support\ItemCost;
use App\Support\ModifierEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Общая база производственных документов — приёмки и цеха.
 *
 * Правило, одинаковое для обоих документов, пишется здесь один раз;
 * различия — абстрактными методами и хуками подклассов.
 *
 * @template TDocument of StoneReception|Workshop
 * @template TItem of StoneReceptionItem|WorkshopItem
 */
abstract class ProductionDocumentService
{
    /** Scope правил отдела — DepartmentModifier::SCOPE_*. */
    abstract protected function modifierScope(): string;

    /** Колонка позиции в снапшоте правил (production_item_modifiers). */
    abstract protected function snapshotForeignKey(): string;

    /**
     * Позиции документа, у которых есть себестоимость.
     *
     * @param  TDocument  $document
     * @return HasMany<TItem, TDocument>
     */
    abstract protected function costedItems(Model $document): HasMany;

    /**
     * SKU партии сырья — от него зависит доступность правил вроде торцовки.
     * У документа без партии — null.
     *
     * @param  TDocument  $document
     */
    protected function batchSku(Model $document): ?string
    {
        return null;
    }

    /**
     * Вызывается в транзакции после пересчёта коэффициентов позиций.
     *
     * @param  TDocument  $document
     */
    protected function afterCoeffsChanged(Model $document): void {}

    /**
     * Правка коэффициентов позиций из формы show-страницы.
     *
     * @param  TDocument  $document
     */
    public function updateItemCoeff(Model $document, array $validated): void
    {
        // Отдел — эффективный, а не голая колонка: у документов, созданных админом,
        // department_id пуст, и правила молча не применились бы.
        $departmentId = $document->effectiveDepartmentId();
        $scope        = $this->modifierScope();
        $batchSku     = $this->batchSku($document);

        DB::transaction(function () use ($document, $validated, $departmentId, $scope, $batchSku) {
            foreach ($validated['items'] as $row) {
                $item = $this->costedItems($document)->with(['product', 'modifiers'])->findOrFail($row['item_id']);

                // База берётся из формы как есть: правила её не переопределяют,
                // поэтому повторное сохранение не сдвигает коэффициент.
                // Сработавшие по SKU правила форма шлёт чекбоксами: неотмеченное — снято.
                // Уже применённые правила остаются со своими числами, даже выключенные
                // в отделе, — до «Обновить правила».
                $keys = $row['modifiers'] ?? [];
                $sku  = $item->product?->sku;

                $cost = ItemCost::computeWithRules(
                    $item->product,
                    (float) $row['base_coeff'],
                    (float) $row['master_base_coeff'],
                    $departmentId,
                    ModifierEngine::resolveKeepingSnapshot($departmentId, $scope, $sku, $keys, $batchSku, $item->modifiers),
                    ModifierEngine::disabledSkuKeys($departmentId, $scope, $sku, $keys),
                );

                $item->update($cost['attributes']);
                ItemCost::syncSnapshot($this->snapshotForeignKey(), $item->id, $cost['modifiers']);
            }

            $this->afterCoeffsChanged($document);
        });
    }

    /**
     * «Обновить коэф.»: базы пильщика и мастера — из справочника товаров. Правила позиции
     * не трогаются — их числа берутся из снапшота; обновить их — refreshItemRules().
     *
     * @param  TDocument  $document
     */
    public function refreshItemCoeffs(Model $document): void
    {
        $departmentId = $document->effectiveDepartmentId();

        DB::transaction(function () use ($document, $departmentId) {
            foreach ($this->costedItems($document)->with(['product', 'modifiers'])->get() as $item) {
                if (!$item->product || $item->product->prod_cost_coeff === null) {
                    continue;
                }

                $cost = ItemCost::computeWithRules(
                    $item->product,
                    (float) $item->product->prod_cost_coeff,
                    (float) ($item->product->master_cost_coeff ?? 0),
                    $departmentId,
                    $item->modifiers,
                    $item->disabled_modifier_keys ?? [],
                );

                $item->update($cost['attributes']);
            }

            $this->afterCoeffsChanged($document);
        });
    }

    /**
     * «Обновить правила»: правила позиций — по текущим настройкам отдела. Выключенные,
     * удалённые и переставшие подходить по маске снимаются, изменённые числа обновляются,
     * новые sku-правила добавляются. Ручной выбор восстанавливается из снапшота, снятые
     * sku-правила остаются снятыми, базовые коэффициенты позиции не меняются.
     *
     * @param  TDocument  $document
     */
    public function refreshItemRules(Model $document): void
    {
        $departmentId = $document->effectiveDepartmentId();
        $scope        = $this->modifierScope();
        $batchSku     = $this->batchSku($document);

        DB::transaction(function () use ($document, $departmentId, $scope, $batchSku) {
            foreach ($this->costedItems($document)->with(['product', 'modifiers'])->get() as $item) {
                $disabledKeys = $item->disabled_modifier_keys ?? [];

                $applied = ModifierEngine::resolve(
                    $departmentId,
                    $scope,
                    $item->product?->sku,
                    ModifierEngine::manualKeysFromSnapshot($departmentId, $item->modifiers),
                    $batchSku,
                    $disabledKeys,
                );

                $cost = ItemCost::computeWithRules(
                    $item->product,
                    (float) ($item->base_cost_coeff ?? $item->product->prod_cost_coeff ?? 0),
                    (float) ($item->master_base_cost_coeff ?? $item->product->master_cost_coeff ?? 0),
                    $departmentId,
                    $applied,
                    $disabledKeys,
                );

                $item->update($cost['attributes']);
                ItemCost::syncSnapshot($this->snapshotForeignKey(), $item->id, $cost['modifiers']);
            }

            $this->afterCoeffsChanged($document);
        });
    }

    /**
     * Есть ли позиция, чьи правила разошлись с текущими правилами отдела, —
     * тогда на show видна кнопка «Обновить правила».
     *
     * @param  TDocument  $document
     */
    public function hasOutdatedRules(Model $document): bool
    {
        $departmentId = $document->effectiveDepartmentId();
        $scope        = $this->modifierScope();
        $batchSku     = $this->batchSku($document);

        return $this->costedItems($document)->with(['product', 'modifiers'])->get()
            ->contains(fn ($item) => ModifierEngine::snapshotOutdated(
                $departmentId,
                $scope,
                $item->product?->sku,
                $item->modifiers,
                $batchSku,
                $item->disabled_modifier_keys ?? [],
            ));
    }

    /**
     * Создать позицию с зафиксированной себестоимостью и снапшотом правил.
     *
     * @param  TDocument  $document
     * @param  array  $attributes  product_id, quantity и прочие поля позиции
     * @param  string[]  $manualKeys  ручные правила, отмеченные в форме
     * @return TItem
     */
    protected function createCostedItem(Model $document, array $attributes, ?Product $product, array $manualKeys = []): Model
    {
        $cost = ItemCost::compute(
            $product,
            $document->effectiveDepartmentId(),
            $this->modifierScope(),
            $manualKeys,
            $this->batchSku($document),
        );

        $item = $this->costedItems($document)->create(array_merge($cost['attributes'], $attributes));

        ItemCost::syncSnapshot($this->snapshotForeignKey(), $item->id, $cost['modifiers']);

        return $item;
    }
}
