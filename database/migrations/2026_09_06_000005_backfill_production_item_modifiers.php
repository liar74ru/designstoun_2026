<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Бэкфил снапшотов применённых правил для уже существующих позиций.
 *
 * Восстанавливается по булевым колонкам is_undercut / is_edging / is_small_tile
 * и по SKU (плитка-маска). Суммы НЕ пересчитываются: worker_cost_per_m2 и
 * master_cost_per_m2 остаются как есть — выплаченные зарплаты не переписываем.
 *
 * Правило ищется в отделе позиции. У документов без отдела (созданных админом
 * или исторических) правил нет, снапшот остаётся пустым — на замороженные
 * суммы это не влияет, а при последующей правке позиции движок пересчитает
 * коэффициент по правилам её текущего отдела.
 *
 * Отдел берётся упрощённо — по колонке документа. Полная цепочка
 * effectiveDepartmentId() требует связей и в миграции была бы хрупкой; для
 * бэкфила снапшотов её точность не критична, потому что суммы не трогаются.
 */
return new class extends Migration
{
    public function up(): void
    {
        $modifiers = DB::table('department_modifiers')
            ->get(['id', 'department_id', 'key', 'name', 'color', 'sort_order',
                   'worker_coeff_delta', 'worker_coeff_replace',
                   'master_coeff_delta', 'master_coeff_replace'])
            ->groupBy('department_id');

        if ($modifiers->isEmpty()) {
            return;
        }

        $this->backfill(
            'stone_reception_items',
            'stone_reception_item_id',
            'stone_receptions',
            'stone_reception_id',
            $modifiers,
        );

        $this->backfill(
            'workshop_items',
            'workshop_item_id',
            'workshops',
            'workshop_id',
            $modifiers,
        );
    }

    public function down(): void
    {
        DB::table('production_item_modifiers')->delete();
    }

    private function backfill(
        string $itemsTable,
        string $itemFk,
        string $docTable,
        string $docFk,
        $modifiers,
    ): void {
        $now = now();

        DB::table($itemsTable)
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($itemsTable, $itemFk, $docTable, $docFk, $modifiers, $now) {
                $departments = DB::table($docTable)
                    ->whereIn('id', $items->pluck($docFk)->filter()->unique())
                    ->pluck('department_id', 'id');

                $skus = DB::table('products')
                    ->whereIn('id', $items->pluck('product_id')->filter()->unique())
                    ->pluck('sku', 'id');

                $rows = [];

                foreach ($items as $item) {
                    $departmentId = $departments[$item->{$docFk}] ?? null;
                    if (! $departmentId) {
                        continue;
                    }

                    $deptRules = ($modifiers[$departmentId] ?? collect())->keyBy('key');
                    $sku       = $skus[$item->product_id] ?? null;

                    $keys = [];
                    if ($this->isMaskTile($sku))          { $keys[] = 'mask_tile'; }
                    if ($item->is_edging)                 { $keys[] = 'edging'; }
                    if ($item->is_undercut)               { $keys[] = 'undercut'; }
                    if ($item->is_small_tile)             { $keys[] = 'small_tile'; }

                    foreach ($keys as $key) {
                        $rule = $deptRules[$key] ?? null;
                        if (! $rule) {
                            continue;
                        }

                        $rows[] = [
                            $itemFk                  => $item->id,
                            'department_modifier_id' => $rule->id,
                            'key'                    => $rule->key,
                            'name'                   => $rule->name,
                            'color'                  => $rule->color,
                            'sort_order'             => $rule->sort_order,
                            'worker_coeff_delta'     => $rule->worker_coeff_delta,
                            'worker_coeff_replace'   => $rule->worker_coeff_replace,
                            'master_coeff_delta'     => $rule->master_coeff_delta,
                            'master_coeff_replace'   => $rule->master_coeff_replace,
                            'created_at'             => $now,
                            'updated_at'             => $now,
                        ];
                    }
                }

                if ($rows) {
                    DB::table('production_item_modifiers')->insert($rows);
                }
            });
    }

    /** Плитка-маска: SKU вида 04-07-XX. */
    private function isMaskTile(?string $sku): bool
    {
        if (!$sku) {
            return false;
        }
        $parts = explode('-', $sku);

        return ($parts[0] ?? '') === '04' && ($parts[1] ?? '') === '07';
    }
};
