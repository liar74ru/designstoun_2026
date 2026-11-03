<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Финальный статус, который отдел поставил заявке «для себя». Строка, а не uuid —
        // как orders.state_moysklad_id: сравнения между ними в PostgreSQL не падают.
        // Отметка живёт в pivot: сняли отдел с заявки — ушла и она.
        Schema::table('order_department', function (Blueprint $table) {
            $table->string('final_state_id')->nullable();
            $table->timestamp('finalized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_department', function (Blueprint $table) {
            $table->dropColumn(['final_state_id', 'finalized_at']);
        });
    }
};
