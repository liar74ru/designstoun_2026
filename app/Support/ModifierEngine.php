<?php

namespace App\Support;

use App\Models\Department;
use App\Models\DepartmentModifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Единая точка расчёта коэффициента себестоимости по правилам отдела.
 *
 * Модель предельно простая: сработать может сколько угодно правил сразу, и все
 * их значения складываются с коэффициентом продукта.
 *
 *     коэффициент = коэффициент продукта + сумма сработавших правил
 *
 * Сложение коммутативно, поэтому порядка применения у правил нет.
 *
 * Наследования у правил нет: отдел не задан или не имеет правил → коэффициент
 * равен базовому. Вызывающий обязан передавать эффективный отдел документа
 * (effectiveDepartmentId()), а не голый department_id — иначе у документов,
 * созданных админом, правила молча не применятся.
 */
class ModifierEngine
{
    private const CACHE_TTL = 86400;

    public static function cacheKey(int $departmentId): string
    {
        return Department::modifiersCacheKey($departmentId);
    }

    /**
     * Активные правила отдела для области.
     *
     * @return Collection<int, DepartmentModifier>
     */
    public static function rulesFor(?int $departmentId, string $scope): Collection
    {
        if ($departmentId === null) {
            return collect();
        }

        $all = Cache::remember(
            self::cacheKey($departmentId),
            self::CACHE_TTL,
            fn () => DepartmentModifier::where('department_id', $departmentId)
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
        );

        return $all->filter(fn (DepartmentModifier $m) => $m->appliesToScope($scope))->values();
    }

    /**
     * Какие правила сработали для позиции.
     *
     * sku-правила срабатывают автоматически по маске SKU продукта;
     * manual-правила — только если их ключ пришёл из формы и правило доступно
     * при данной партии сырья.
     *
     * @param  string[] $manualKeys Ключи ручных правил, отмеченных пользователем
     * @return Collection<int, DepartmentModifier>
     */
    public static function resolve(
        ?int $departmentId,
        string $scope,
        ?string $productSku,
        array $manualKeys = [],
        ?string $batchSku = null,
    ): Collection {
        return self::rulesFor($departmentId, $scope)
            ->filter(function (DepartmentModifier $rule) use ($productSku, $manualKeys, $batchSku) {
                if ($rule->trigger === DepartmentModifier::TRIGGER_SKU) {
                    return $rule->matchesSku($productSku);
                }

                return in_array($rule->key, $manualKeys, true)
                    && $rule->availableForBatchSku($batchSku);
            })
            ->values();
    }

    /**
     * Применить правила к базовому коэффициенту для роли:
     * коэффициент продукта плюс сумма всех сработавших правил.
     *
     * Сложение коммутативно, поэтому порядок правил на результат не влияет.
     *
     * @param iterable $applied Правила или их снапшоты — оба отдают effectFor()
     */
    public static function apply(float $baseCoeff, iterable $applied, string $role): float
    {
        $coeff = $baseCoeff;

        foreach ($applied as $rule) {
            $coeff += $rule->effectFor($role);
        }

        return $coeff;
    }

    /** Коэффициент пильщика. */
    public static function workerCoeff(float $baseCoeff, iterable $applied): float
    {
        return self::apply($baseCoeff, $applied, DepartmentModifier::ROLE_WORKER);
    }

    /**
     * Коэффициент мастера. База — коэффициент продукта master_cost_coeff,
     * а не prod_cost_coeff: у мастера своя шкала.
     */
    public static function masterCoeff(float $masterBaseCoeff, iterable $applied): float
    {
        return self::apply($masterBaseCoeff, $applied, DepartmentModifier::ROLE_MASTER);
    }

    /** Сбросить кэш правил отдела. */
    public static function forget(int $departmentId): void
    {
        Cache::forget(self::cacheKey($departmentId));
    }

    /** Ключи ручных правил из булевых флагов старых форм (мост на время этапа 2). */
    public static function manualKeysFromLegacyFlags(bool $isUndercut, bool $isEdging): array
    {
        $keys = [];
        if ($isUndercut) {
            $keys[] = 'undercut';
        }
        if ($isEdging) {
            $keys[] = 'edging';
        }

        return $keys;
    }
}
