<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /** Пароль для локальной установки; в production не используется. */
    private const DEV_PASSWORD = '12345678';

    private const MIN_PASSWORD_LENGTH = 8;

    public function run(): void
    {
        $phone    = (string) config('app.admin.phone');
        $password = $this->password();

        // Используем транзакцию — либо создаётся всё, либо ничего
        DB::transaction(function () use ($phone, $password) {

            // Удаляем старые записи если есть (при переустановке)
            User::where('phone', $phone)->delete();
            Worker::where('phone', $phone)->delete();

            // 1. Создаём worker-запись для администратора
            $worker = Worker::create([
                'name'     => 'Администратор',
                'phone'    => $phone,
                'position' => 'Администратор',
            ]);

            // 2. Создаём user и сразу привязываем к worker
            $user = User::create([
                'name'      => 'Администратор',
                'phone'     => $phone,
                'email'     => null,
                'password'  => Hash::make($password),
                'is_admin'  => true,
                'worker_id' => $worker->id,
            ]);

            // 3. Обратная связь worker → user (если есть колонка user_id в workers)
            if (in_array('user_id', Schema::getColumnListing('workers'))) {
                $worker->update(['user_id' => $user->id]);
            }
        });

        $this->command?->info("✅ Администратор создан. Телефон: {$phone}");
        if ($password === self::DEV_PASSWORD) {
            $this->command?->warn('⚠️  Пароль по умолчанию 12345678 — только для локальной установки.');
        }
    }

    /**
     * Пароль из ADMIN_PASSWORD. В production стандартного пароля нет: иначе любая
     * свежая установка открыта всем, кто знает 12345678 из репозитория.
     */
    private function password(): string
    {
        $password = (string) config('app.admin.password');

        if ($password === '') {
            if (app()->isProduction()) {
                throw new RuntimeException('ADMIN_PASSWORD не задан: в production администратор без пароля не создаётся.');
            }

            return self::DEV_PASSWORD;
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new RuntimeException('ADMIN_PASSWORD короче ' . self::MIN_PASSWORD_LENGTH . ' символов.');
        }

        return $password;
    }
}
