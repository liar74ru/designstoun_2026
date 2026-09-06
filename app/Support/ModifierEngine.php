<?php

namespace App\Support;

use App\Models\Department;
use App\Models\DepartmentModifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Единая точка расчёта коэффициента себестоимости по правилам отдела.
 *
 * Правила применяются ПО ПОРЯДКУ (sort_order), и порядок значим:
 *   - `replace` обнуляет накопленное и подставляет своё значение;
 *   - `delta` прибавляет к накопленному.
 *
 * Такая композиция воспроизводит прежнюю захардкоженную формулу точно:
 * торцовка (replace, sort 20) отменяет бонус маски (delta, sort 10),
 * но не отменяет подкол (delta, sort 30).
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
     * Активные правила отдела для области, отсортированные по sort_order.
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
                ->orderBy('sort_order')
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
     * Применить правила к базовому коэффициенту для роли.
     *
     * @param iterable $applied Правила или их снапшоты — оба отдают effectFor()
     */
    public static function apply(float $baseCoeff, iterable $applied, string $role): float
    {
        $coeff = $baseCoeff;

        foreach (self::sorted($applied) as $rule) {
            ['delta' => $delta, 'replace' => $replace] = $rule->effectFor($role);

            if ($replace !== null) {
                $coeff = $replace;
                continue;
            }

            if ($delta !== null) {
                $coeff += $delta;
            }
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

    /**
     * Порядок применения задаёт результат, поэтому сортируем всегда —
     * снапшоты могут прийти из БД в произвольном порядке.
     */
    private static function sorted(iterable $applied): Collection
    {
        return collect($applied)
            ->sortBy(fn ($rule) => (int) ($rule->sort_order ?? 0))
            ->values();
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
