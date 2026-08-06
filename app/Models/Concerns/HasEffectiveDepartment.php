<?php

namespace App\Models\Concerns;

/**
 * Эффективный отдел документа: собственный `department_id`, а если он пуст —
 * отдел связанной сущности (партии сырья, работника). Цепочку задаёт сама
 * модель через effectiveDepartmentChain().
 *
 * Нужен там, где документ должен относиться к отделу даже без записанного
 * department_id: у документов, созданных администратором (у него нет отдела),
 * и у исторических записей колонка бывает пустой.
 */
trait HasEffectiveDepartment
{
    /**
     * Порядок разрешения отдела: 'department_id' — своя колонка,
     * '<relation>.department_id' — отдел связанной модели.
     *
     * @return array<int, string>
     */
    abstract public static function effectiveDepartmentChain(): array;

    public function effectiveDepartmentId(): ?int
    {
        foreach (static::effectiveDepartmentChain() as $tier) {
            $id = str_contains($tier, '.')
                ? $this->{explode('.', $tier)[0]}?->department_id
                : $this->{$tier};

            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Документы, чей эффективный отдел входит в $ids.
     *
     * $ids === null — без ограничения (админ).
     * $includeUndetermined — добавить документы, у которых отдел не определяется
     * ни на одном звене цепочки.
     *
     * Условия строятся на whereIn/whereHas/whereDoesntHave (IN + EXISTS), без
     * джойнов: джойн по hasMany задваивал бы строки и ломал бы with().
     */
    public function scopeInEffectiveDepartments($query, ?array $ids, bool $includeUndetermined = false)
    {
        if ($ids === null) {
            return $query;
        }

        $chain = static::effectiveDepartmentChain();

        // Внешний where() обязателен: иначе orWhere «протекут» на соседние фильтры.
        return $query->where(function ($q) use ($chain, $ids, $includeUndetermined) {
            foreach ($chain as $i => $tier) {
                $q->orWhere(function ($q2) use ($chain, $i, $tier, $ids) {
                    // Звенья с более высоким приоритетом пусты — иначе отдел взялся бы из них.
                    static::whereTiersEmpty($q2, array_slice($chain, 0, $i));
                    static::whereTierIn($q2, $tier, $ids);
                });
            }

            if ($includeUndetermined) {
                $q->orWhere(fn ($q2) => static::whereTiersEmpty($q2, $chain));
            }
        });
    }

    private static function whereTierIn($query, string $tier, array $ids): void
    {
        if (str_contains($tier, '.')) {
            $query->whereHas(explode('.', $tier)[0], fn ($q) => $q->whereIn('department_id', $ids));

            return;
        }

        $query->whereIn($tier, $ids);
    }

    /**
     * Звено «пустое» = связи нет либо у связанной модели нет отдела —
     * ровно семантика `?:` из effectiveDepartmentId().
     *
     * @param  array<int, string>  $tiers
     */
    private static function whereTiersEmpty($query, array $tiers): void
    {
        foreach ($tiers as $tier) {
            if (str_contains($tier, '.')) {
                $query->whereDoesntHave(
                    explode('.', $tier)[0],
                    fn ($q) => $q->whereNotNull('department_id')
                );

                continue;
            }

            $query->whereNull($tier);
        }
    }
}
