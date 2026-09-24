<?php

namespace App\Models;

use App\Support\BadgeColor;
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
 * ставки надбавки пересчитываются пропорционально сами. Значение правила
 * складывается с коэффициентом продукта; сработать может несколько правил
 * сразу, и порядок на результат не влияет.
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

    /**
     * Набор иконок плашек (Bootstrap Icons) — единственный источник и для формы,
     * и для валидации. Хранится класс целиком: <i class="bi {{ $icon }}">.
     */
    public const ICONS = [
        'bi-lightning-charge-fill'     => 'Молния',
        'bi-scissors'                  => 'Ножницы',
        'bi-grid-3x3'                  => 'Мелкая сетка',
        'bi-mask'                      => 'Маска',
        'bi-rulers'                    => 'Размер',
        'bi-hammer'                    => 'Обработка',
        'bi-gem'                       => 'Премиум',
        'bi-fire'                      => 'Термо',
        'bi-droplet-fill'              => 'Влажная резка',
        'bi-star-fill'                 => 'Особое',
        'bi-exclamation-triangle-fill' => 'Внимание',
        'bi-snow'                      => 'Холод',
    ];

    /** Цвет плашки, когда правилу его не задали. */
    public const COLOR_FALLBACK = '#6C757D';

    protected $fillable = [
        'department_id',
        'key',
        'name',
        'color',
        'icon',
        'trigger',
        'sku_pattern',
        'available_when_batch_sku',
        'applies_to',
        'worker_coeff_delta',
        'master_coeff_delta',
        'is_active',
    ];

    protected $casts = [
        'worker_coeff_delta' => 'decimal:4',
        'master_coeff_delta' => 'decimal:4',
        'is_active'          => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Цвет текста на плашке правила. Расчёт общий для всех цветных плашек —
     * App\Support\BadgeColor::textFor(), зеркало textColorOn() из modifier-picker.js.
     */
    public static function textColorFor(?string $background): string
    {
        return BadgeColor::textFor($background ?: self::COLOR_FALLBACK);
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

    /** Слагаемое к коэффициенту для роли; 0 — правило на эту роль не влияет. */
    public function effectFor(string $role): float
    {
        $delta = $role === self::ROLE_MASTER ? $this->master_coeff_delta : $this->worker_coeff_delta;

        return (float) ($delta ?? 0);
    }
}
