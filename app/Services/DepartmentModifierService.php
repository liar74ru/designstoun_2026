<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DepartmentModifier;
use Illuminate\Support\Facades\DB;

/**
 * Управление правилами-модификаторами себестоимости отдела.
 *
 * Наследования у правил нет: новый отдел стартует с пустым списком и ничего
 * не прибавляет к коэффициенту, пока админ не заведёт правила. Поэтому здесь
 * есть заполнение стандартным набором из config/production_modifiers.php.
 */
class DepartmentModifierService
{
    /**
     * Заполнить отдел стандартным набором правил.
     * Уже существующие ключи не трогаются — повторный вызов безопасен.
     *
     * @return int Сколько правил добавлено
     */
    public function applyDefaults(Department $department): int
    {
        $existing = $department->modifiers()->pluck('key')->all();
        $added    = 0;

        DB::transaction(function () use ($department, $existing, &$added) {
            foreach (config('production_modifiers', []) as $key => $rule) {
                if (in_array($key, $existing, true)) {
                    continue;
                }

                $department->modifiers()->create(array_merge($rule, [
                    'key'       => $key,
                    'is_active' => true,
                ]));
                $added++;
            }
        });

        $department->forgetSettingsCache();

        return $added;
    }

    /**
     * Скопировать правила из другого отдела.
     * Существующие ключи целевого отдела сохраняются.
     *
     * @return int Сколько правил скопировано
     */
    public function copyToDepartment(Department $source, Department $target): int
    {
        $existing = $target->modifiers()->pluck('key')->all();
        $copied   = 0;

        DB::transaction(function () use ($source, $target, $existing, &$copied) {
            foreach ($source->modifiers as $modifier) {
                if (in_array($modifier->key, $existing, true)) {
                    continue;
                }

                $target->modifiers()->create(
                    collect($modifier->getAttributes())
                        ->except(['id', 'department_id', 'created_at', 'updated_at'])
                        ->all()
                );
                $copied++;
            }
        });

        $target->forgetSettingsCache();

        return $copied;
    }

    /** Удалить правило и сбросить кэш отдела. */
    public function delete(DepartmentModifier $modifier): void
    {
        $department = $modifier->department;
        $modifier->delete();
        $department?->forgetSettingsCache();
    }
}
