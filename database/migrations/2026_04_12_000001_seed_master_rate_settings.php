<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $settings = [
        [
            'key'         => 'MASTER_BASE_RATE',
            'value'       => '100',
            'label'       => 'Базовая ставка, ₽/м²',
            'description' => 'Базовая ставка мастера за каждый м² принятой продукции.',
        ],
        [
            'key'         => 'MASTER_UNDERCUT_RATE',
            'value'       => '50',
            'label'       => 'Подкол > 80%, ₽/м²',
            'description' => 'Ставка за приёмки с флагом подкол > 80%.',
        ],
        [
            'key'         => 'MASTER_PACKAGING_RATE',
            'value'       => '30',
            'label'       => 'Фасовка в ящик, ₽/м²',
            'description' => 'Ставка за фасовку продукции в ящик.',
        ],
        [
            'key'         => 'MASTER_SMALL_TILE_RATE',
            'value'       => '50',
            'label'       => 'Плитка < 50мм, ₽/м²',
            'description' => 'Ставка за приёмку мелкой плитки (менее 50 мм).',
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, ['created_at' => $now, 'updated_at' => $now]),
            );
        }
    }

    public function down(): void
    {
        $keys = array_column($this->settings, 'key');
        DB::table('settings')->whereIn('key', $keys)->delete();
    }
};
