<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            // Добавляем внешний ключ на departments
            $table->foreignId('department_id')
                ->nullable()
                ->after('position')
                ->constrained('departments')
                ->nullOnDelete(); // При удалении отдела ставим NULL
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            // Удаляем внешний ключ и столбец
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
