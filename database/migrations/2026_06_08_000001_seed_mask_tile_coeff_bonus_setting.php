<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $settings = [
        [
            'key'         => 'MASK_TILE_COEFF_BONUS',
            'value'       => '2',
            'label'       => 'Бонус коэффициента «Маска» (04-07-xx)',
            'description' => 'Добавляется к prod_cost_coeff для плитки SKU 04-07-xx. Влияет на зарплату резчика и себестоимость в МойСклад.',
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
