<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopPreset extends Model
{
    protected $fillable = [
        'department_id',
        'name',
    ];

    /**
     * Инвариант «основной отдел всегда среди отделов пресета» — как у Worker.
     */
    protected static function booted(): void
    {
        static::saved(function (WorkshopPreset $preset) {
            if ($preset->department_id && ($preset->wasRecentlyCreated || $preset->wasChanged('department_id'))) {
                $preset->departments()->syncWithoutDetaching([$preset->department_id]);
                $preset->unsetRelation('departments');
            }
        });
    }

    /** Основной отдел: в нём пресет создан, удаление отсюда убирает пресет из всех отделов. */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /** Все отделы, где пресет доступен (основной — тоже). */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_workshop_preset')->orderBy('name');
    }

    /** Группы отдела, в которых показан пресет (может быть несколько или ни одной). */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(WorkshopPresetGroup::class, 'workshop_preset_group_preset')->orderBy('name');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkshopPresetItem::class)->orderBy('id');
    }
}
