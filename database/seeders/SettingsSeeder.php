<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key'         => 'PIECE_RATE',
                'value'       => '390',
                'label'       => 'Базовая ставка пильщика (PIECE_RATE), ₽',
                'description' => 'Ставка за 1 ед. продукции. Формула: ОКРУГЛВНИЗ((ставка + ставка×17%×коэф) / 10) × 10. Влияет на зарплату и дашборд.',
            ],
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
            [
                'key'         => 'MOYSKLAD_ORDER_STATUSES',
                'value'       => json_encode(['Новая', 'В процессе', 'Собран'], JSON_UNESCAPED_UNICODE),
                'label'       => 'Статусы заявок для синхронизации',
                'description' => 'JSON-массив имён статусов customerorder в МойСклад, которые подгружаются при синхронизации заявок. Управляется на отдельной странице.',
            ],
        ];

        foreach ($settings as $data) {
            Setting::firstOrCreate(['key' => $data['key']], $data);
        }
    }
}
