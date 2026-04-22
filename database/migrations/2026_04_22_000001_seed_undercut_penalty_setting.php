<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $settings = [
        [
            'key'         => 'UNDERCUT_PENALTY',
            'value'       => '1.5',
            'label'       => 'Штраф подкол > 80%',
            'description' => 'Вычитается из базового коэффициента пильщика при флаге «подкол > 80%».',
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
