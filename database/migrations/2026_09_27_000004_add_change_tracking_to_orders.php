<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Изменение состава заявки: синхронизация сравнивает количество по товарам со старым
     * составом, мастер видит «было → стало», пока не нажмёт «Принято».
     *
     * Статус «Изменено» (is_changed) — пауза внутри производства: окно не закрывается,
     * «Принято» возвращает заявку в статус, который был до него (state_before_change).
     */
    public function up(): void
    {
        Schema::table('order_states', function (Blueprint $t) {
            $t->boolean('track_changes')->default(true)
                ->comment('Следить за изменением позиций заявок в этом статусе');
            $t->boolean('is_changed')->default(false)->comment('Статус «Изменено»');
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('positions_changed_at')->nullable()->after('production_ended_at')
                ->comment('Есть непринятые изменения позиций');
            $t->json('position_changes')->nullable()->after('positions_changed_at')
                ->comment('{product_moysklad_id: {name, from, to}} с последнего «Принято»');
            $t->string('state_before_change')->nullable()->after('position_changes')
                ->comment('Статус до «Изменено» — в него вернёт «Принято»');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['positions_changed_at', 'position_changes', 'state_before_change']);
        });

        Schema::table('order_states', function (Blueprint $t) {
            $t->dropColumn(['track_changes', 'is_changed']);
        });
    }
};
