<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Department extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'manager_id',
        'is_active',
        'default_raw_store_id',
        'default_product_store_id',
        'default_production_store_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @return HasMany<Worker, $this> */
    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    /** @return HasMany<Worker, $this> */
    public function activeWorkers(): HasMany
    {
        return $this->hasMany(Worker::class)->orderBy('name');
    }

    /**
     * Все работники отдела (включая тех, для кого он не основной)
     *
     * @return BelongsToMany<Worker, $this>
     */
    public function allWorkers(): BelongsToMany
    {
        return $this->belongsToMany(Worker::class, 'department_worker')->withTimestamps();
    }

    /** @return BelongsTo<Worker, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'manager_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function defaultRawStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'default_raw_store_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function defaultProductStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'default_product_store_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function defaultProductionStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'default_production_store_id');
    }

    /** @return HasMany<DepartmentOperationSetting, $this> */
    public function operationSettings(): HasMany
    {
        return $this->hasMany(DepartmentOperationSetting::class);
    }

    /**
     * Переопределения настроек себестоимости для этого отдела
     *
     * @return HasMany<DepartmentSetting, $this>
     */
    public function settings(): HasMany
    {
        return $this->hasMany(DepartmentSetting::class);
    }

    /**
     * Произвольные строки накладных расходов отдела (₽/м²)
     *
     * @return HasMany<DepartmentExpense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(DepartmentExpense::class)->orderBy('id');
    }

    /**
     * Правила-модификаторы себестоимости отдела (бонусы и штрафы).
     * Читать только через App\Support\ModifierEngine.
     *
     * @return HasMany<DepartmentModifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(DepartmentModifier::class)->orderBy('id');
    }

    /**
     * Пресеты цеха, доступные отделу: свои и подключённые из других отделов.
     *
     * @return BelongsToMany<WorkshopPreset, $this>
     */
    public function presets(): BelongsToMany
    {
        return $this->belongsToMany(WorkshopPreset::class, 'department_workshop_preset');
    }

    /**
     * Пресеты, для которых отдел основной (создаются через эту связь).
     *
     * @return HasMany<WorkshopPreset, $this>
     */
    public function ownedPresets(): HasMany
    {
        return $this->hasMany(WorkshopPreset::class);
    }

    /** @return HasMany<WorkshopPresetGroup, $this> */
    public function presetGroups(): HasMany
    {
        return $this->hasMany(WorkshopPresetGroup::class)->orderBy('name');
    }

    /**
     * Заявки, распределённые на этот отдел
     *
     * @return BelongsToMany<Order, $this>
     */
    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'order_department');
    }

    /**
     * Список ключей операций, явно включённых в этом отделе.
     *
     * @return string[]
     */
    public function enabledOperationKeys(): array
    {
        return $this->operationSettings()
            ->where('enabled', true)
            ->pluck('operation_key')
            ->all();
    }

    /**
     * Map [operation_key => positions[]] для всех настроек этого отдела.
     *
     * @return array<string, string[]>
     */
    public function allowedPositionsByOperation(): array
    {
        $map = [];
        foreach ($this->operationSettings()->get(['operation_key', 'config']) as $row) {
            $map[$row->operation_key] = $row->config['positions'] ?? [];
        }

        return $map;
    }

    public static function operationsCacheKey(int $departmentId): string
    {
        return "dept.{$departmentId}.ops";
    }

    public static function operationPositionsCacheKey(int $departmentId, string $operationKey): string
    {
        return "dept.{$departmentId}.op.{$operationKey}.positions";
    }

    public static function positionAllowedFor(int $departmentId, string $operationKey, string $position): bool
    {
        $positions = Cache::remember(
            self::operationPositionsCacheKey($departmentId, $operationKey),
            300,
            function () use ($departmentId, $operationKey) {
                $cfg = DepartmentOperationSetting::where('department_id', $departmentId)
                    ->where('operation_key', $operationKey)
                    ->value('config');

                return is_array($cfg) ? ($cfg['positions'] ?? []) : [];
            }
        );

        return in_array($position, $positions, true);
    }

    public static function settingsCacheKey(int $departmentId): string
    {
        return "dept.{$departmentId}.settings";
    }

    public static function expensesCacheKey(int $departmentId): string
    {
        return "dept.{$departmentId}.expenses";
    }

    public static function modifiersCacheKey(int $departmentId): string
    {
        return "dept.{$departmentId}.modifiers";
    }

    /** Единая точка сброса кэша себестоимости отдела: ставки, расходы и правила. */
    public function forgetSettingsCache(): void
    {
        Cache::forget(self::settingsCacheKey($this->id));
        Cache::forget(self::expensesCacheKey($this->id));
        Cache::forget(self::modifiersCacheKey($this->id));
    }

    public function forgetOperationsCache(): void
    {
        Cache::forget(self::operationsCacheKey($this->id));
        foreach (array_keys(config('department_operations', [])) as $key) {
            Cache::forget(self::operationPositionsCacheKey($this->id, $key));
        }
    }
}
