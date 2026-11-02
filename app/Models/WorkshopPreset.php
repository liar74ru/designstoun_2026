<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopPreset extends Model
{
    protected $fillable = [
        'department_id',
        'workshop_preset_group_id',
        'name',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function group()
    {
        return $this->belongsTo(WorkshopPresetGroup::class, 'workshop_preset_group_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkshopPresetItem::class)->orderBy('id');
    }
}
