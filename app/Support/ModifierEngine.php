<?php

namespace App\Support;

use App\Models\Department;
use App\Models\DepartmentModifier;
use App\Models\ProductionItemModifier;
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
     * Все активные правила отдела, без фильтра по области.
     * Нужны формам: они отдают правила обеих областей в JS одной картой.
     *
     * @return Collection<int, DepartmentModifier>
     */
    public static function allFor(?int $departmentId): Collection
    {
        if ($departmentId === null) {
            return collect();
        }

        return Cache::remember(
            self::cacheKey($departmentId),
            self::CACHE_TTL,
            fn () => DepartmentModifier::where('department_id', $departmentId)
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * Активные правила отдела для области.
     *
     * @return Collection<int, DepartmentModifier>
     */
    public static function rulesFor(?int $departmentId, string $scope): Collection
    {
        return self::allFor($departmentId)
            ->filter(fn (DepartmentModifier $m) => $m->appliesToScope($scope))
            ->values();
    }

    /**
     * Какие правила сработали для позиции.
     *
     * sku-правила срабатывают автоматически по маске SKU продукта, если
     * пользователь не снял их у позиции; manual-правила — только если их ключ
     * пришёл из формы и правило доступно при данной партии сырья.
     *
     * @param  string[] $manualKeys   Ключи ручных правил, отмеченных пользователем
     * @param  string[] $disabledKeys Ключи sku-правил, снятых у позиции
     * @return Collection<int, DepartmentModifier>
     */
    public static function resolve(
        ?int $departmentId,
        string $scope,
        ?string $productSku,
        array $manualKeys = [],
        ?string $batchSku = null,
        array $disabledKeys = [],
    ): Collection {
        return self::rulesFor($departmentId, $scope)
            ->filter(function (DepartmentModifier $rule) use ($productSku, $manualKeys, $batchSku, $disabledKeys) {
                if ($rule->trigger === DepartmentModifier::TRIGGER_SKU) {
                    return $rule->matchesSku($productSku)
                        && !in_array($rule->key, $disabledKeys, true);
                }

                return in_array($rule->key, $manualKeys, true)
                    && $rule->availableForBatchSku($batchSku);
            })
            ->values();
    }

    /**
     * Какие sku-правила пользователь снял в форме правки коэффициентов.
     *
     * Форма рисует сработавшие по SKU правила чекбоксами и шлёт ключи отмеченных
     * вместе с ручными: сработавшее, но не пришедшее — снято.
     *
     * @param  string[] $submittedKeys Все ключи, отмеченные в форме
     * @return string[]
     */
    public static function disabledSkuKeys(
        ?int $departmentId,
        string $scope,
        ?string $productSku,
        array $submittedKeys,
    ): array {
        return self::rulesFor($departmentId, $scope)
            ->filter(fn (DepartmentModifier $rule) => $rule->trigger === DepartmentModifier::TRIGGER_SKU
                && $rule->matchesSku($productSku)
                && !in_array($rule->key, $submittedKeys, true))
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * Правила позиции при сохранении панели «Коэффициенты»: снапшот главнее настроек отдела.
     *
     * Правило, уже применённое к позиции, остаётся со своими (снапшотными) числами, пока его
     * не сняли в форме, — даже если в отделе его выключили, удалили, сменили маску или число.
     * Новые правила (отмеченные ручные, сработавшие по SKU) берутся текущими. Обновить
     * снапшот до текущих правил — refreshItemRules() («Обновить правила»).
     *
     * @param  string[] $submittedKeys Все ключи, отмеченные в форме
     * @param  iterable $snapshot      Строки production_item_modifiers позиции
     * @return Collection<int, DepartmentModifier|ProductionItemModifier>
     */
    public static function resolveKeepingSnapshot(
        ?int $departmentId,
        string $scope,
        ?string $productSku,
        array $submittedKeys,
        ?string $batchSku,
        iterable $snapshot,
    ): Collection {
        $snapshotByKey = collect($snapshot)->keyBy('key');

        $current = self::resolve(
            $departmentId,
            $scope,
            $productSku,
            $submittedKeys,
            $batchSku,
            self::disabledSkuKeys($departmentId, $scope, $productSku, $submittedKeys),
        )->map(fn (DepartmentModifier $rule) => $snapshotByKey->get($rule->key) ?? $rule);

        $currentKeys = $current->pluck('key')->all();

        $retired = $snapshotByKey
            ->filter(fn ($row, $key) => in_array($key, $submittedKeys, true) && !in_array($key, $currentKeys, true))
            ->values();

        return $current->concat($retired)->values();
    }

    /**
     * Разошёлся ли снапшот позиции с текущими правилами отдела: правило выключили, удалили,
     * оно перестало подходить по маске, у него сменилось число или появилось новое
     * sku-правило. Имя, цвет и иконка не в счёт — на деньги не влияют.
     *
     * @param  iterable $snapshot     Строки production_item_modifiers позиции
     * @param  string[] $disabledKeys Снятые у позиции sku-правила
     */
    public static function snapshotOutdated(
        ?int $departmentId,
        string $scope,
        ?string $productSku,
        iterable $snapshot,
        ?string $batchSku,
        array $disabledKeys,
    ): bool {
        $signature = fn (iterable $rules) => collect($rules)
            ->map(fn ($rule) => $rule->key . ':'
                . round($rule->effectFor(DepartmentModifier::ROLE_WORKER), 4) . ':'
                . round($rule->effectFor(DepartmentModifier::ROLE_MASTER), 4))
            ->sort()
            ->values()
            ->all();

        $current = self::resolve(
            $departmentId,
            $scope,
            $productSku,
            self::manualKeysFromSnapshot($departmentId, $snapshot),
            $batchSku,
            $disabledKeys,
        );

        return $signature($snapshot) !== $signature($current);
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

    /**
     * Ключи ручных правил, отмеченных для позиции ранее — из её снапшота.
     *
     * Нужны при пересчёте: sku-правила движок определит сам по SKU, а вот
     * выбор пользователя восстанавливать неоткуда, кроме снапшота. Ключи
     * сверяются с текущими ручными правилами отдела, чтобы снятое с правила
     * ручное срабатывание не оживало.
     *
     * @param  iterable $snapshot Строки production_item_modifiers позиции
     * @return string[]
     */
    public static function manualKeysFromSnapshot(?int $departmentId, iterable $snapshot): array
    {
        $manual = self::allFor($departmentId)
            ->where('trigger', DepartmentModifier::TRIGGER_MANUAL)
            ->pluck('key')
            ->all();

        return collect($snapshot)
            ->pluck('key')
            ->filter(fn (?string $key) => in_array($key, $manual, true))
            ->unique()
            ->values()
            ->all();
    }
}
