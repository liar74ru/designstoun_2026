<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WorkshopPresetGroup extends Model
{
    /** Набор иконок группы: рисует форму и служит whitelist'ом валидации. */
    public const ICONS = [
        'bi-box'              => 'Коробка',
        'bi-box-seam'         => 'Упаковка',
        'bi-grid-3x3-gap'     => 'Плитка',
        'bi-grid-3x3'         => 'Мелкая сетка',
        'bi-layers'           => 'Слои',
        'bi-bricks'           => 'Кирпич',
        'bi-border-outer'     => 'Бордюр',
        'bi-circle'           => 'Круг',
        'bi-hexagon'          => 'Шестигранник',
        'bi-gem'              => 'Премиум',
        'bi-star-fill'        => 'Особое',
        'bi-fire'             => 'Термо',
        'bi-droplet-fill'     => 'Влажная',
        'bi-hammer'           => 'Обработка',
        'bi-rulers'           => 'Размер',
        'bi-tag'              => 'Метка',
    ];

    protected $fillable = [
        'department_id',
        'name',
        'icon',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function presets(): BelongsToMany
    {
        return $this->belongsToMany(WorkshopPreset::class, 'workshop_preset_group_preset')->orderBy('name');
    }
}
