<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->json('positions')->default('[]')->after('position');
        });

        // Перенос данных: строка 'Мастер' → массив ['Мастер']
        // Используем Eloquent чтобы не зависеть от SQL-диалекта
        DB::table('workers')->whereNotNull('position')->where('position', '!=', '')->eachById(function ($worker) {
            DB::table('workers')->where('id', $worker->id)->update([
                'positions' => json_encode([$worker->position]),
            ]);
        });

        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('position')->nullable()->after('positions');
        });

        // Восстановление: берём первый элемент массива
        DB::table('workers')->eachById(function ($worker) {
            $positions = json_decode($worker->positions, true);
            DB::table('workers')->where('id', $worker->id)->update([
                'position' => $positions[0] ?? null,
            ]);
        });

        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('positions');
        });
    }
};
