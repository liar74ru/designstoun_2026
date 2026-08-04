<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * MASTER_PACKAGING_RATE никогда не участвовала в расчёте (только отображалась),
 * MASTER_SMALL_TILE_RATE заменена коэффициентом продукта master_cost_coeff.
 */
return new class extends Migration
{
    private const DEAD_KEYS = ['MASTER_PACKAGING_RATE', 'MASTER_SMALL_TILE_RATE'];

    public function up(): void
    {
        Setting::whereIn('key', self::DEAD_KEYS)->delete();

        foreach (self::DEAD_KEYS as $key) {
            Cache::forget('setting.' . $key);
        }
    }

    public function down(): void
    {
        Setting::firstOrCreate(['key' => 'MASTER_PACKAGING_RATE'], [
            'value'       => '30',
            'label'       => 'Фасовка в ящик, ₽/м²',
            'description' => 'Ставка за фасовку продукции в ящик.',
        ]);
        Setting::firstOrCreate(['key' => 'MASTER_SMALL_TILE_RATE'], [
            'value'       => '50',
            'label'       => 'Плитка < 50мм, ₽/м²',
            'description' => 'Ставка за приёмку мелкой плитки (менее 50 мм).',
        ]);
    }
};
