<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'moysklad_id',
        'name',
        'path_name',
        'code',
        'external_code',
        'parent_id',
        'attributes',
    ];

    protected $casts = [
        'attributes' => 'array',
    ];

    /**
     * Получить товары в этой группе
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'group_id', 'moysklad_id');
    }

    /**
     * Получить родительскую группу
     */
    public function parent()
    {
        return $this->belongsTo(ProductGroup::class, 'parent_id', 'moysklad_id');
    }

    /**
     * Получить дочерние группы
     */
    public function children()
    {
        return $this->hasMany(ProductGroup::class, 'parent_id', 'moysklad_id');
    }

    /**
     * Получить все дочерние группы рекурсивно
     */
    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }

    /**
     * Получить полный путь группы
     */
    public function getFullPathAttribute(): string
    {
        if ($this->path_name) {
            return $this->path_name;
        }

        $path = [$this->name];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' / ', $path);
    }

    /**
     * Получить уровень вложенности
     */
    public function getLevelAttribute(): int
    {
        $level = 0;
        $parent = $this->parent;

        while ($parent) {
            $level++;
            $parent = $parent->parent;
        }

        return $level;
    }
    /**
     * Получить количество товаров в группе включая подгруппы
     */
    public function getTotalProductsCountAttribute(): int
    {
        $count = $this->products()->count();

        foreach ($this->children as $child) {
            $count += $child->total_products_count;
        }

        return $count;
    }
}
