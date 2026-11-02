<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Product;
use App\Models\WorkshopItem;
use App\Models\WorkshopPreset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkshopPresetService
{
    /** Соответствие корневых имён массивов формы ролям строк (как в форме цеха). */
    private const ROLE_BY_INPUT = [
        'raw_materials' => WorkshopItem::ROLE_RAW,
        'packages'      => WorkshopItem::ROLE_PACKAGE,
        'products'      => WorkshopItem::ROLE_PRODUCT,
    ];

    public function getForDepartment(Department $department): Collection
    {
        return $department->presets()->with(['group', 'items.product'])->orderBy('name')->get();
    }

    public function create(Department $department, array $data): WorkshopPreset
    {
        return DB::transaction(function () use ($department, $data) {
            $preset = $department->presets()->create([
                'name'                     => $data['name'],
                'workshop_preset_group_id' => $data['workshop_preset_group_id'] ?? null,
            ]);
            $this->createItems($preset, $data);

            return $preset;
        });
    }

    public function update(WorkshopPreset $preset, array $data): WorkshopPreset
    {
        return DB::transaction(function () use ($preset, $data) {
            $preset->update([
                'name'                     => $data['name'],
                'workshop_preset_group_id' => $data['workshop_preset_group_id'] ?? null,
            ]);
            $preset->items()->delete();
            $this->createItems($preset, $data);

            return $preset;
        });
    }

    public function delete(WorkshopPreset $preset): void
    {
        $preset->delete();
    }

    public function copyToDepartment(WorkshopPreset $preset, Department $target): WorkshopPreset
    {
        return DB::transaction(function () use ($preset, $target) {
            $name = $preset->name;
            while ($target->presets()->where('name', $name)->exists()) {
                $name .= ' (копия)';
            }

            // Группа принадлежит отделу: в целевом берём одноимённую, иначе — без группы.
            $groupId = $preset->group
                ? $target->presetGroups()->where('name', $preset->group->name)->value('id')
                : null;

            $copy = $target->presets()->create([
                'name'                     => $name,
                'workshop_preset_group_id' => $groupId,
            ]);
            foreach ($preset->items as $item) {
                $copy->items()->create([
                    'product_id' => $item->product_id,
                    'role'       => $item->role,
                    'quantity'   => $item->quantity,
                ]);
            }

            return $copy;
        });
    }

    /**
     * Группы и пресеты отдела для формы цеха:
     * {groups: [{id, name, icon}], presets: [{id, name, color, group_id, items: [{role, product_id, product_label, quantity}]}]}.
     * group_id = null — пресет без группы.
     */
    public function getPresetsJsonFor(Department $department): array
    {
        return [
            'groups'  => $department->presetGroups()->get(['id', 'name', 'icon'])
                ->map(fn($group) => $group->only(['id', 'name', 'icon']))
                ->all(),
            'presets' => $this->getForDepartment($department)->map(fn($preset) => [
                'id'       => $preset->id,
                'name'     => $preset->name,
                'group_id' => $preset->workshop_preset_group_id,
                'color'    => Product::getColorBySku(
                    $preset->items->firstWhere('role', WorkshopItem::ROLE_PRODUCT)?->product?->sku
                ),
                'items'    => $preset->items->map(fn($item) => [
                    'role'          => $item->role,
                    'product_id'    => $item->product_id,
                    'product_label' => $item->product?->name ?? '',
                    'quantity'      => (float) $item->quantity,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Строки пресета для префилла формы: [{role, product_id, product_label, quantity}].
     * При ошибке валидации восстанавливает строки из old-инпута.
     */
    public function prefillItems(?WorkshopPreset $preset, ?array $oldInput = null): array
    {
        if ($oldInput !== null) {
            return $this->prefillFromOldInput($oldInput);
        }

        if (!$preset) {
            return [];
        }

        $preset->loadMissing('items.product');

        return $preset->items->map(fn($item) => [
            'role'          => $item->role,
            'product_id'    => $item->product_id,
            'product_label' => $item->product?->name ?? '',
            'quantity'      => (float) $item->quantity,
        ])->values()->all();
    }

    private function prefillFromOldInput(array $oldInput): array
    {
        $rows = [];
        foreach (self::ROLE_BY_INPUT as $input => $role) {
            foreach ($oldInput[$input] ?? [] as $row) {
                $rows[] = [
                    'role'       => $role,
                    'product_id' => $row['product_id'] ?? '',
                    'quantity'   => $row['quantity'] ?? '',
                ];
            }
        }

        $names = Product::whereIn('id', array_filter(array_column($rows, 'product_id')))
            ->pluck('name', 'id');

        return array_map(fn($row) => $row + [
            'product_label' => $names[$row['product_id']] ?? '',
        ], $rows);
    }

    private function createItems(WorkshopPreset $preset, array $data): void
    {
        foreach (self::ROLE_BY_INPUT as $input => $role) {
            foreach ($data[$input] ?? [] as $row) {
                if (empty($row['product_id']) || (float) ($row['quantity'] ?? 0) <= 0) {
                    continue;
                }
                $preset->items()->create([
                    'product_id' => $row['product_id'],
                    'role'       => $role,
                    'quantity'   => $row['quantity'],
                ]);
            }
        }
    }
}
