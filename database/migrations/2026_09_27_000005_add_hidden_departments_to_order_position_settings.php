<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Отделы, для которых позиция смешанной заявки скрыта в списке заявок.
     * Хранится список «скрыто для», а не «отдел позиции»: позиция может быть нужна
     * нескольким отделам сразу, и по умолчанию видна всем.
     */
    public function up(): void
    {
        Schema::table('order_position_settings', function (Blueprint $t) {
            $t->jsonb('hidden_department_ids')->nullable()->comment('Отделы, для которых позиция скрыта в списке заявок');
        });
    }

    public function down(): void
    {
        Schema::table('order_position_settings', function (Blueprint $t) {
            $t->dropColumn('hidden_department_ids');
        });
    }
};
