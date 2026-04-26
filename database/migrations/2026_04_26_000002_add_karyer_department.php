<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('departments')->where('code', 'КАРЬЕР')->doesntExist()) {
            DB::table('departments')->insert([
                'name'       => 'Карьер',
                'code'       => 'КАРЬЕР',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('departments')->where('code', 'КАРЬЕР')->delete();
    }
};
