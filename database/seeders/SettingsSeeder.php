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
        ];

        foreach ($settings as $data) {
            Setting::firstOrCreate(['key' => $data['key']], $data);
        }
    }
}
