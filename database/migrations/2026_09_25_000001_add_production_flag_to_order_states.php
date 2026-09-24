<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Отметка «производственный статус»: пока заявка в нём, программа считает
        // изготовленное. Флаг на статусе, а не имя в коде — имена в МойСклад переименовывают.
        Schema::table('order_states', function (Blueprint $table) {
            $table->boolean('is_production')->default(false)->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('order_states', function (Blueprint $table) {
            $table->dropColumn('is_production');
        });
    }
};
