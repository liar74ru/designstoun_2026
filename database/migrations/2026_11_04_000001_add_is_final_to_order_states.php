<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Финальный статус (например, «Собран»): работа отдела по заявке закончена.
        // У заявки нескольких отделов каждый ставит его только для себя — общим он
        // становится, когда отметились все (см. OrderDepartmentStateService).
        Schema::table('order_states', function (Blueprint $table) {
            $table->boolean('is_final')->default(false)->after('is_list_bottom');
        });
    }

    public function down(): void
    {
        Schema::table('order_states', function (Blueprint $table) {
            $table->dropColumn('is_final');
        });
    }
};
