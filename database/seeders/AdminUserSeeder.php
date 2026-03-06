<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Очищаем таблицу пользователей перед созданием
        // truncate сбрасывает и счётчик auto-increment
        User::truncate();

        User::create([
            'name'      => 'Администратор',
            'phone'     => '89128993488',
            'email'     => null,
            'password'  => Hash::make('12345678'),
            'is_admin'  => true,
            'worker_id' => null,
        ]);

        $this->command->info('✅ Администратор создан. Телефон: 89128993488');
    }
}
