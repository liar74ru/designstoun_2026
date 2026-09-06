<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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

    public function workers()
    {
        return $this->hasMany(Worker::class);
    }

    public function activeWorkers()
    {
        return $this->hasMany(Worker::class)->orderBy('name');
    }

    /** Все работники отдела (включая тех, для кого он не основной) */
    public function allWorkers()
    {
        return $this->belongsToMany(Worker::class, 'department_worker')->withTimestamps();
    }

    public function manager()
    {
        return $this->belongsTo(Worker::class, 'manager_id');
    }

    public function defaultRawStore()
    {
        return $this->belongsTo(Store::class, 'default_raw_store_id');
    }

    public function defaultProductStore()
    {
        return $this->belongsTo(Store::class, 'default_product_store_id');
    }

    public function defaultProductionStore()
    {
        return $this->belongsTo(Store::class, 'default_production_store_id');
    }

    public function operationSettings(): HasMany
    {
        return $this->hasMany(DepartmentOperationSetting::class);
    }

    /** Переопределения настроек себестоимости для этого отдела */
    public function settings(): HasMany
    {
        return $this->hasMany(DepartmentSetting::class);
    }

    /** Произвольные строки накладных расходов отдела (₽/м²) */
    public function expenses(): HasMany
    {
        return $this->hasMany(DepartmentExpense::class)->orderBy('id');
    }

    /**
     * Правила-модификаторы себестоимости отдела (бонусы и штрафы).
     * Читать только через App\Support\ModifierEngine.
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(DepartmentModifier::class)->orderBy('sort_order')->orderBy('id');
    }

    public function presets(): HasMany
    {
        return $this->hasMany(WorkshopPreset::class);
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
