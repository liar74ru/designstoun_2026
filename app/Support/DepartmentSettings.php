<?php

namespace App\Support;

use App\Models\Department;
use App\Models\DepartmentExpense;
use App\Models\DepartmentSetting;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Единая точка чтения настроек себестоимости отдела. Две разные модели:
 *
 * 1. Числовые настройки (ставки мастера) — с фолбэком
 *    «настройка отдела → глобальный Setting → default в коде».
 *    Отдел может быть не задан (админские/исторические документы) — тогда
 *    берётся глобальное значение, поэтому все методы принимают ?int.
 *
 * 2. Накладные расходы (`overheadPerUnit()`) — произвольный список строк
 *    в `department_expenses`, БЕЗ наследования. Нет отдела → 0.
 *
 * Кэш: «dept.{id}.settings» (карта настроек) и «dept.{id}.expenses» (сумма
 * расходов), оба сбрасываются через Department::forgetSettingsCache().
 * В карте кэшируются только явно заданные значения, поэтому правка
 * глобальной настройки подхватывается сразу.
 */
class DepartmentSettings
{
    private const CACHE_TTL = 86400;

    /**
     * Map [key => value] явно заданных настроек отдела.
     *
     * @return array<string, string>
     */
    public static function map(int $departmentId): array
    {
        return Cache::remember(
            Department::settingsCacheKey($departmentId),
            self::CACHE_TTL,
            fn () => DepartmentSetting::where('department_id', $departmentId)
                ->pluck('value', 'key')
                ->all()
        );
    }

    /**
     * Значение настройки для отдела с фолбэком на глобальное.
     * Пустая строка трактуется как «не задано».
     */
    public static function get(?int $departmentId, string $key, mixed $default = null): mixed
    {
        if ($departmentId !== null) {
            $value = self::map($departmentId)[$key] ?? null;
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return Setting::get($key, $default);
    }

    public static function float(?int $departmentId, string $key, float $default = 0): float
    {
        return (float) self::get($departmentId, $key, $default);
    }

    /**
     * Сумма накладных расходов отдела на единицу продукции (₽/м²).
     *
     * Расходы — произвольный список строк в `department_expenses`, наследования
     * из глобальных настроек у них НЕТ: документ без отдела даёт 0.
     * Поэтому вызывающий обязан передавать эффективный отдел документа
     * (см. StoneReception::effectiveDepartmentId()).
     */
    public static function overheadPerUnit(?int $departmentId): float
    {
        if ($departmentId === null) {
            return 0.0;
        }

        return Cache::remember(
            Department::expensesCacheKey($departmentId),
            self::CACHE_TTL,
            fn () => (float) DepartmentExpense::where('department_id', $departmentId)->sum('amount')
        );
    }

    /**
     * Базовая ставка пильщика за единицу продукции (₽/ед).
     *
     * Каждый отдел задаёт её сам: подъём зарплаты не обязан быть одномоментным.
     * Отдел не задал — наследуется глобальное значение.
     */
    public static function pieceRate(?int $departmentId): float
    {
        return self::float($departmentId, 'PIECE_RATE', 390);
    }

    /** Базовая ставка мастера за единицу продукции (₽/м²). */
    public static function masterBaseRate(?int $departmentId): float
    {
        return self::float($departmentId, 'MASTER_BASE_RATE', 100);
    }

    /** Надбавка мастеру за флаг «подкол > 80%» (₽/м²). */
    public static function masterUndercutRate(?int $departmentId): float
    {
        return self::float($departmentId, 'MASTER_UNDERCUT_RATE', 50);
    }

    /**
     * Плоский список всех ключей, настраиваемых per-department (whitelist).
     *
     * @return string[]
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (config('department_settings', []) as $group) {
            $keys = array_merge($keys, array_keys($group['keys'] ?? []));
        }

        return $keys;
    }
}
