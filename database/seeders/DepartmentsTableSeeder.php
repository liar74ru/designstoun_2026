<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;

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

        foreach ($departments as $dept) {
            Department::create($dept);
        }
    }
}
