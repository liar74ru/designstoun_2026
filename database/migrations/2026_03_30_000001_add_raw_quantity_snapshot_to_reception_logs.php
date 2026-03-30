<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reception_logs', function (Blueprint $table) {
            // Количество сырья в партии ДО применения этой записи лога.
            // Позволяет исторически восстановить состояние партии на момент приёмки.
            $table->decimal('raw_quantity_snapshot', 10, 3)->nullable()->after('raw_quantity_delta');
        });
    }

    public function down(): void
    {
        Schema::table('reception_logs', function (Blueprint $table) {
            $table->dropColumn('raw_quantity_snapshot');
        });
    }
};
