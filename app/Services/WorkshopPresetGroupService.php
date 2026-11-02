<?php

namespace App\Services;

use App\Models\Department;
use App\Models\WorkshopPresetGroup;

class WorkshopPresetGroupService
{
    public function create(Department $department, array $data): WorkshopPresetGroup
    {
        return $department->presetGroups()->create([
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
        ]);
    }

    public function update(WorkshopPresetGroup $group, array $data): WorkshopPresetGroup
    {
        $group->update([
            'name' => $data['name'],
            'icon' => $data['icon'] ?? null,
        ]);

        return $group;
    }

    /** Пресеты группы не удаляются: FK nullOnDelete оставляет их «без группы». */
    public function delete(WorkshopPresetGroup $group): void
    {
        $group->delete();
    }
}
