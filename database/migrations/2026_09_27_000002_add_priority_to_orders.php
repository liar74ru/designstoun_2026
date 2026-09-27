<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Приоритет заявки: по нему сортируется список и делится между заявками
     * изготовленное по общему товару.
     *
     * priority_key — одна колонка на все критерии: авто-ключ из срока отгрузки и даты
     * заявки либо ключ, выставленный ручным перемещением (priority_manual).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('delivery_planned_at')->nullable()->after('moment')
                ->comment('Планируемая дата отгрузки (deliveryPlannedMoment МойСклад)');
            $t->boolean('is_urgent')->default(false)->comment('Срочная — закреплена сверху');
            $t->double('priority_key')->default(0)->comment('Меньше — выше в очереди');
            $t->boolean('priority_manual')->default(false)
                ->comment('Ключ выставлен вручную, синхронизация его не пересчитывает');
            $t->index(['is_urgent', 'priority_key']);
        });

        // Формула инлайнена, а не взята из App\Support\OrderPriority: миграция не должна
        // ломаться, когда правило ключа поменяется. Срока у существующих заявок ещё нет.
        DB::table('orders')->orderBy('id')->each(function ($order) {
            $moment = $order->moment ? strtotime($order->moment) : 0;

            DB::table('orders')->where('id', $order->id)
                ->update(['priority_key' => 99999 * 1e10 + $moment]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['is_urgent', 'priority_key']);
            $t->dropColumn(['delivery_planned_at', 'is_urgent', 'priority_key', 'priority_manual']);
        });
    }
};
