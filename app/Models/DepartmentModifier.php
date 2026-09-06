<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Правило-модификатор себестоимости отдела: бонус или штраф, правящий
 * коэффициент продукта при расчёте зарплаты пильщика и мастера.
 *
 * Набор у каждого отдела свой, наследования нет: отдел без правил ничего
 * не прибавляет и не вычитает. Читать только через App\Support\ModifierEngine.
 *
 * Эффект задаётся коэффициентами, не рублями — тогда при подъёме базовой
 * ставки надбавки пересчитываются пропорционально сами.
 */
class DepartmentModifier extends Model
{
    /** Срабатывает вручную — чекбоксом в форме. */
    public const TRIGGER_MANUAL = 'manual';
    /** Срабатывает автоматически по маске SKU продукта. */
    public const TRIGGER_SKU = 'sku';

    public const SCOPE_RECEPTION = 'reception';
    public const SCOPE_WORKSHOP  = 'workshop';
    public const SCOPE_BOTH      = 'both';

    public const ROLE_WORKER = 'worker';
    public const ROLE_MASTER = 'master';

    /**
     * Палитра плашек — единственный источник и для формы, и для валидации.
     * Значения совпадают с цветами Bootstrap, которыми бейджи красились раньше.
     */
    public const COLORS = [
        '#FFC107' => 'Жёлтый',
        '#0DCAF0' => 'Голубой',
        '#DC3545' => 'Красный',
        '#198754' => 'Зелёный',
        '#0D6EFD' => 'Синий',
        '#6F42C1' => 'Фиолетовый',
        '#6C757D' => 'Серый',
    ];

    protected $fillable = [
        'department_id',
        'key',
        'name',
        'color',
        'trigger',
        'sku_pattern',
        'available_when_batch_sku',
        'applies_to',
        'worker_coeff_delta',
        'worker_coeff_replace',
        'master_coeff_delta',
        'master_coeff_replace',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'worker_coeff_delta'   => 'decimal:4',
        'worker_coeff_replace' => 'decimal:4',
        'master_coeff_delta'   => 'decimal:4',
        'master_coeff_replace' => 'decimal:4',
        'sort_order'           => 'integer',
        'is_active'            => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Действует ли правило в этой области (приёмка/цех). */
    public function appliesToScope(string $scope): bool
    {
        return $this->applies_to === self::SCOPE_BOTH || $this->applies_to === $scope;
    }

    /**
     * Совпадает ли строка с маской вида «04-07-*» или «*-*-30».
     * `*` заменяет один сегмент SKU целиком.
     */
    public static function matchesPattern(?string $value, ?string $pattern): bool
    {
        if (!$value || !$pattern) {
            return false;
        }

        $valueParts   = explode('-', $value);
        $patternParts = explode('-', $pattern);

        foreach ($patternParts as $i => $part) {
            if ($part === '*') {
                // Сегмент должен существовать, но может быть любым.
                if (!isset($valueParts[$i])) {
                    return false;
                }
                continue;
            }

            if (($valueParts[$i] ?? null) !== $part) {
                return false;
            }
        }

        return true;
    }

    /** Срабатывает ли sku-правило на этом SKU. */
    public function matchesSku(?string $sku): bool
    {
        return $this->trigger === self::TRIGGER_SKU
            && self::matchesPattern($sku, $this->sku_pattern);
    }

    /**
     * Доступно ли ручное правило при данной партии сырья.
     *
     * Пустое условие — доступно всегда. Неизвестный SKU партии (null) тоже
     * считается разрешающим: у операций цеха партии нет вовсе, а торцовка там
     * доступна. Строгая проверка появится вместе с переездом форм на правила.
     */
    public function availableForBatchSku(?string $batchSku): bool
    {
        if (empty($this->available_when_batch_sku) || $batchSku === null) {
            return true;
        }

        return self::matchesPattern($batchSku, $this->available_when_batch_sku);
    }

    /** Значения эффекта для роли: ['delta' => ?float, 'replace' => ?float]. */
    public function effectFor(string $role): array
    {
        $delta   = $role === self::ROLE_MASTER ? $this->master_coeff_delta : $this->worker_coeff_delta;
        $replace = $role === self::ROLE_MASTER ? $this->master_coeff_replace : $this->worker_coeff_replace;

        return [
            'delta'   => $delta === null ? null : (float) $delta,
            'replace' => $replace === null ? null : (float) $replace,
        ];
    }
}
