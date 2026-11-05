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

    /**
     * Пресеты отдела (свои и подключённые). Группы — только этого отдела: у пресета из
     * нескольких отделов в каждом свои группы.
     */
    public function getForDepartment(Department $department): Collection
    {
        return $department->presets()
            ->with([
                'groups' => fn ($q) => $q->where('workshop_preset_groups.department_id', $department->id),
                'department',
                'departments',
                'items.product',
            ])
            ->orderBy('name')
            ->get();
    }

    public function create(Department $department, array $data): WorkshopPreset
    {
        return DB::transaction(function () use ($department, $data) {
            $preset = $department->ownedPresets()->create(['name' => $data['name']]);
            $this->syncDepartments($preset, $department, $data['department_ids'] ?? []);
            $preset->groups()->sync($data['group_ids'] ?? []);
            $this->createItems($preset, $data);

            return $preset;
        });
    }

    /**
     * Правка из карточки отдела $department: группы синхронизируются только этого отдела,
     * группы остальных отделов пресета не трогаются.
     */
    public function update(Department $department, WorkshopPreset $preset, array $data): WorkshopPreset
    {
        return DB::transaction(function () use ($department, $preset, $data) {
            $preset->update(['name' => $data['name']]);
            $this->syncDepartments($preset, $department, $data['department_ids'] ?? []);

            $otherGroups = $preset->groups()
                ->where('workshop_preset_groups.department_id', '!=', $department->id)
                ->pluck('workshop_preset_groups.id');
            $preset->groups()->sync($otherGroups->merge($data['group_ids'] ?? [])->all());

            $preset->items()->delete();
            $this->createItems($preset, $data);

            return $preset;
        });
    }

    /** Удаление целиком — из всех отделов (только из основного отдела). */
    public function delete(WorkshopPreset $preset): void
    {
        $preset->delete();
    }

    /** Убрать подключённый пресет из отдела: отвязка от отдела и его групп. Основной не отвязывается. */
    public function detach(WorkshopPreset $preset, Department $department): void
    {
        if ($preset->department_id === $department->id) {
            throw new \LogicException('Пресет нельзя убрать из основного отдела — только удалить.');
        }

        DB::transaction(function () use ($preset, $department) {
            $this->detachDepartments($preset, [$department->id]);
        });
    }

    /**
     * Перед удалением отдела: его пресеты, подключённые к другим отделам, получают нового
     * основного (первый по имени), остальные удаляются вместе с отделом.
     */
    public function handOverOwnedPresets(Department $department): void
    {
        DB::transaction(function () use ($department) {
            foreach ($department->ownedPresets()->with('departments')->get() as $preset) {
                $heir = $preset->departments->firstWhere('id', '!=', $department->id);
                $heir ? $preset->update(['department_id' => $heir->id]) : $preset->delete();
            }
        });
    }

    public function copyToDepartment(WorkshopPreset $preset, Department $source, Department $target): WorkshopPreset
    {
        return DB::transaction(function () use ($preset, $source, $target) {
            $name = $preset->name;
            while ($target->presets()->where('name', $name)->exists()) {
                $name .= ' (копия)';
            }

            $copy = $target->ownedPresets()->create(['name' => $name]);

            // Группы принадлежат отделу: берём группы источника и ищем одноимённые в целевом.
            $sourceGroups = $preset->groups()->where('workshop_preset_groups.department_id', $source->id)->pluck('name');
            $copy->groups()->sync(
                $target->presetGroups()->whereIn('name', $sourceGroups)->pluck('id')
            );
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
     * {groups: [{id, name, icon}], presets: [{id, name, color, group_ids, items: [{role, product_id, product_label, quantity}]}]}.
     * Пустой group_ids — пресет без группы.
     */
    public function getPresetsJsonFor(Department $department): array
    {
        return [
            'groups'  => $department->presetGroups()->get(['id', 'name', 'icon'])
                ->map(fn ($group) => $group->only(['id', 'name', 'icon']))
                ->all(),
            'presets' => $this->getForDepartment($department)->map(fn ($preset) => [
                'id'        => $preset->id,
                'name'      => $preset->name,
                'group_ids' => $preset->groups->pluck('id')->all(),
                'color'     => Product::getColorBySku(
                    $preset->items->firstWhere('role', WorkshopItem::ROLE_PRODUCT)?->product?->sku
                ),
                'items'     => $preset->items->map(fn ($item) => [
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

        return $preset->items->map(fn ($item) => [
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

        return array_map(fn ($row) => $row + [
            'product_label' => $names[$row['product_id']] ?? '',
        ], $rows);
    }

    /**
     * Отделы пресета = отмеченные в форме + основной + отдел, из карточки которого правят
     * (снять их в форме нельзя). Снятые отделы теряют и свои группы.
     */
    private function syncDepartments(WorkshopPreset $preset, Department $department, array $departmentIds): void
    {
        $keep = collect($departmentIds)->map(fn ($id) => (int) $id)
            ->push($department->id, $preset->department_id)
            ->filter()->unique()->values();

        $removed = $preset->departments()->pluck('departments.id')->diff($keep)->all();
        if ($removed) {
            $this->detachDepartments($preset, $removed);
        }

        $preset->departments()->syncWithoutDetaching($keep->all());
        $preset->unsetRelation('departments');
    }

    private function detachDepartments(WorkshopPreset $preset, array $departmentIds): void
    {
        $preset->departments()->detach($departmentIds);
        $preset->groups()->detach(
            $preset->groups()->whereIn('workshop_preset_groups.department_id', $departmentIds)->pluck('workshop_preset_groups.id')
        );
        $preset->unsetRelation('departments');
        $preset->unsetRelation('groups');
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
