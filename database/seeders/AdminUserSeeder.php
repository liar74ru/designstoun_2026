<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Используем транзакцию — либо создаётся всё, либо ничего
        DB::transaction(function () {

            // Удаляем старые записи если есть (при переустановке)
            User::where('phone', '89123456789')->delete();
            Worker::where('phone', '89123456789')->delete();

            // 1. Создаём worker-запись для администратора
            $worker = Worker::create([
                'name'     => 'Администратор',
                'phone'    => '89123456789',
                'position' => 'Администратор',
            ]);

            // 2. Создаём user и сразу привязываем к worker
            $user = User::create([
                'name'      => 'Администратор',
                'phone'     => '89123456789',
                'email'     => null,
                'password'  => Hash::make('12345678'),
                'is_admin'  => true,
                'worker_id' => $worker->id,
            ]);

            // 3. Обратная связь worker → user (если есть колонка user_id в workers)
            // Проверяем через getConnection чтобы не упасть если колонки нет
            if (in_array('user_id', \Illuminate\Support\Facades\Schema::getColumnListing('workers'))) {
                $worker->update(['user_id' => $user->id]);
            }
        });

        $this->command->info('✅ Worker «Администратор» создан. Телефон: 89128993489');
        $this->command->info('✅ User «Администратор» создан. Телефон: 89128993488, пароль: 12345678');
        $this->command->warn('⚠️  Не забудьте сменить пароль после первого входа!');
    }
}
