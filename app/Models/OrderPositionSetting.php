<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Настройки позиции заявки: склады комплектации, снимок остатка на входе в производство
 * и поправки мастера к остатку и к изготовленному.
 *
 * Поправки хранятся дельтами, а не готовыми числами: дальнейшие движения по товару
 * переносятся на уточнённое значение сами.
 */
class OrderPositionSetting extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'stores',
        'frozen_stocks',
        'stock_delta',
        'stock_base',
        'produced_delta',
        'produced_base',
        'note',
        'user_id',
        'ready_at',
        'ready_user_id',
        'hidden_department_ids',
    ];

    protected $casts = [
        'stores'         => 'array',
        'frozen_stocks'  => 'array',
        'stock_delta'    => 'decimal:3',
        'stock_base'     => 'decimal:3',
        'produced_delta' => 'decimal:3',
        'produced_base'  => 'decimal:3',
        'ready_at'       => 'datetime',
        'hidden_department_ids' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Остаток показан снимком, а не живым значением. */
    public function isFrozen(): bool
    {
        return ! empty($this->frozen_stocks);
    }

    /** Позиция вручную отмечена готовой. */
    public function isReady(): bool
    {
        return $this->ready_at !== null;
    }

    /** Отделы, для которых позиция скрыта в списке заявок. */
    public function hiddenDepartmentIds(): array
    {
        return array_map('intval', $this->hidden_department_ids ?? []);
    }

    /** Мастер трогал числа руками. */
    public function hasCorrections(): bool
    {
        return (float) $this->stock_delta !== 0.0 || (float) $this->produced_delta !== 0.0;
    }
}
