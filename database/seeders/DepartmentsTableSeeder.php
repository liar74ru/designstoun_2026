<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Services\DepartmentModifierService;

class DepartmentsTableSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Продажи', 'code' => 'SALES'],
            ['name' => 'Маркетинг', 'code' => 'MARKETING'],
            ['name' => 'Разработка', 'code' => 'DEV'],
            ['name' => 'HR', 'code' => 'HR'],
            ['name' => 'Бухгалтерия', 'code' => 'ACCOUNTING'],
            ['name' => 'Администрация', 'code' => 'ADMIN'],
            ['name' => 'Логистика', 'code' => 'LOGISTICS'],
            ['name' => 'Производство', 'code' => 'PRODUCTION'],
            ['name' => 'Цех', 'code' => 'ЦЕХ'],
            ['name' => 'Галтовка', 'code' => 'ГАЛТОВКА'],
            ['name' => 'МАФ', 'code' => 'МАФ'],
            ['name' => '3д Панель', 'code' => '3д Панель'],
            ['name' => 'Рынок', 'code' => 'Рынок'],
            ['name' => 'Карьер', 'code' => 'КАРЬЕР'],
        ];

        // Правила себестоимости не наследуются, а миграция заводит их только
        // отделам, существовавшим на момент её применения. Отделы из сидера
        // создаются позже, поэтому набор им проставляем здесь — иначе на чистой
        // установке подкол и торцовка молча не работали бы.
        $modifiers = app(DepartmentModifierService::class);

        // Часть отделов заводится миграциями (например, «Карьер» —
        // 2026_04_26_000002), поэтому создаём по коду, а не вслепую.
        foreach ($departments as $dept) {
            $modifiers->applyDefaults(
                Department::firstOrCreate(['code' => $dept['code']], $dept)
            );
        }
    }
}
