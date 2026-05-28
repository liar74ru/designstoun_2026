<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $settings = [
        [
            'key'         => 'EDGING_COEFF',
            'value'       => '-2.5',
            'label'       => 'Коэффициент «Торцовка»',
            'description' => 'Полностью заменяет коэффициент продукта при флаге «Торцовка» (доступен для партий сырья с SKU 04-XX). Может быть отрицательным.',
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
