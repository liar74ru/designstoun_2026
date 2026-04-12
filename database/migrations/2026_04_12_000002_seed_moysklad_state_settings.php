<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $settings = [
        [
            'key'         => 'MOYSKLAD_IN_WORK_STATE',
            'value'       => 'В работе',
            'label'       => 'Статус «В работе»',
            'description' => 'Точное имя статуса в МойСклад, который назначается при создании техоперации.',
        ],
        [
            'key'         => 'MOYSKLAD_DONE_STATE',
            'value'       => 'Завершена',
            'label'       => 'Статус «Завершена»',
            'description' => 'Точное имя статуса в МойСклад, который назначается при завершении техоперации.',
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
