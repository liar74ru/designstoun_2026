<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
//        User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'q@q'],
            [
                'name' => 'Test User',
                'password' => '12345678',
                'email_verified_at' => now(),
            ]
        );
    }
}
