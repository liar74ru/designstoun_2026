<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Снапшот правила, применённого к позиции документа в момент её создания.
 *
 * Хранит копию значений, а не только ссылку: правка правила задним числом
 * не должна переписывать уже выплаченные зарплаты. Тот же принцип, что у
 * замороженных worker_cost_per_m2 / master_cost_per_m2.
 *
 * Заполнена ровно одна из двух FK — позиция принадлежит либо приёмке, либо цеху.
 */
class ProductionItemModifier extends Model
{
    protected $fillable = [
        'stone_reception_item_id',
        'workshop_item_id',
        'department_modifier_id',
        'key',
        'name',
        'color',
        'worker_coeff_delta',
        'master_coeff_delta',
    ];

    protected $casts = [
        'worker_coeff_delta' => 'decimal:4',
        'master_coeff_delta' => 'decimal:4',
    ];

    public function receptionItem(): BelongsTo
    {
        return $this->belongsTo(StoneReceptionItem::class, 'stone_reception_item_id');
    }

    public function workshopItem(): BelongsTo
    {
        return $this->belongsTo(WorkshopItem::class, 'workshop_item_id');
    }

    /** Исходное правило; null, если его удалили в настройках отдела. */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(DepartmentModifier::class, 'department_modifier_id');
    }

    /** Слагаемое к коэффициенту для роли — сигнатура совпадает с DepartmentModifier. */
    public function effectFor(string $role): float
    {
        $delta = $role === DepartmentModifier::ROLE_MASTER ? $this->master_coeff_delta : $this->worker_coeff_delta;

        return (float) ($delta ?? 0);
    }

    /** Снимок правила для записи в снапшот позиции. */
    public static function attributesFrom(DepartmentModifier $modifier): array
    {
        return [
            'department_modifier_id' => $modifier->id,
            'key'                    => $modifier->key,
            'name'                   => $modifier->name,
            'color'                  => $modifier->color,
            'worker_coeff_delta'     => $modifier->worker_coeff_delta,
            'master_coeff_delta'     => $modifier->master_coeff_delta,
        ];
    }
}
