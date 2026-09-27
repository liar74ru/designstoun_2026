<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ручная отметка «позиция готова». Живёт в настройках позиции, а не в order_items:
     * позиции пересоздаются при каждой синхронизации заявок.
     */
    public function up(): void
    {
        Schema::table('order_position_settings', function (Blueprint $t) {
            $t->timestamp('ready_at')->nullable()->comment('Позиция отмечена готовой');
            $t->foreignId('ready_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_position_settings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('ready_user_id');
            $t->dropColumn('ready_at');
        });
    }
};
