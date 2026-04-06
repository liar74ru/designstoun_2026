<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'stores';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'code', 'external_code', 'description', 'address',
        'address_full', 'archived', 'shared', 'path_name', 'account_id',
        'owner_id', 'parent_id', 'attributes',
    ];

    protected $casts = [
        'address_full' => 'array',
        'attributes' => 'array',
        'archived' => 'boolean',
        'shared' => 'boolean',
    ];

    const DEFAULT_STORE_CODE = '-dggJ2jngG51VKi5mHao91'; // external_code склада по умолчанию (6. Склад Уралия Цех)

    /**
     * Найти склад по умолчанию: сначала по коду из config/env,
     * потом по константе, потом первый попавшийся.
     */
    public static function getDefault(): ?self
    {
        $code = config('app.default_store_code') ?: self::DEFAULT_STORE_CODE;
        return self::where('external_code', $code)->first() ?? self::first();
    }

    /**
     * Получить родительский склад (группу)
     */
    public function parent()
    {
        return $this->belongsTo(Store::class, 'parent_id', 'id');
    }

    /**
     * Получить дочерние склады
     */
    public function children()
    {
        return $this->hasMany(Store::class, 'parent_id', 'id');
    }

    /**
     * Получить все остатки на этом складе
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class, 'store_id');
    }

    /**
     * Получить товары с остатками на этом складе
     */
    public function products(): HasMany
    {
        return $this->stocks()
            ->with('product');
    }
}
