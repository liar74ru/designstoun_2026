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
                'key'         => 'BLADE_WEAR',
                'value'       => '50',
                'label'       => 'Расход пилы, ₽/м²',
                'description' => 'Затраты на износ пильного диска за единицу продукции.',
            ],
            [
                'key'         => 'RECEPTION_COST',
                'value'       => '170',
                'label'       => 'Приёмка, ₽/м²',
                'description' => 'Стоимость приёмки продукции.',
            ],
            [
                'key'         => 'PACKAGING_COST',
                'value'       => '30',
                'label'       => 'Упаковка, ₽/м²',
                'description' => null,
            ],
            [
                'key'         => 'WASTE_REMOVAL',
                'value'       => '30',
                'label'       => 'Вывоз мусора, ₽/м²',
                'description' => null,
            ],
            [
                'key'         => 'ELECTRICITY',
                'value'       => '30',
                'label'       => 'Электричество, ₽/м²',
                'description' => null,
            ],
            [
                'key'         => 'PPE_COST',
                'value'       => '15',
                'label'       => 'СИЗ, ₽/м²',
                'description' => 'Средства индивидуальной защиты.',
            ],
            [
                'key'         => 'FORKLIFT_COST',
                'value'       => '30',
                'label'       => 'Кара/погрузчик, ₽/м²',
                'description' => null,
            ],
            [
                'key'         => 'MACHINE_COST',
                'value'       => '50',
                'label'       => 'Станок/камнекол, ₽/м²',
                'description' => null,
            ],
            [
                'key'         => 'RENT_COST',
                'value'       => '35',
                'label'       => 'Аренда, ₽/м²',
                'description' => null,
            ],
            [
                'key'         => 'OTHER_COSTS',
                'value'       => '150',
                'label'       => 'Прочее, ₽/м²',
                'description' => null,
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

        foreach ($settings as $data) {
            Setting::firstOrCreate(['key' => $data['key']], $data);
        }
    }
}
