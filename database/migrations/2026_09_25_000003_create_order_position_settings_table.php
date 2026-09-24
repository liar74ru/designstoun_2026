<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Строка настроек позиции заявки: с каких складов собираем, снимок остатка на входе
     * в производство и поправки мастера. Заменяет order_stock_corrections — там в ключе
     * был склад, а теперь склад у позиции не один.
     *
     * Привязка к product_id, а не к order_item_id: позиции пересоздаются при каждой
     * синхронизации (CustomerOrderSyncService::upsertOrder).
     */
    public function up(): void
    {
        Schema::create('order_position_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->json('stores')->nullable()->comment('Склады, с которых собираем; null — склад заявки');
            $t->json('frozen_stocks')->nullable()->comment('Снимок остатков по складам на входе в производство');
            $t->decimal('stock_delta', 12, 3)->default(0)->comment('Поправка мастера к остатку');
            $t->decimal('stock_base', 12, 3)->default(0)->comment('Остаток на момент уточнения');
            $t->decimal('produced_delta', 12, 3)->default(0)->comment('Поправка мастера к изготовленному');
            $t->decimal('produced_base', 12, 3)->default(0)->comment('Изготовлено по документам на момент уточнения');
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['order_id', 'product_id']);
        });

        $this->migrateCorrections();

        Schema::dropIfExists('order_stock_corrections');
    }

    /**
     * Перенос старых поправок: склад уезжает в stores, delta — в stock_delta.
     * Одна строка на пару заявка+товар — в старом ключе был ещё склад, поэтому
     * при коллизии оставляем первую (складом заявки был ровно один).
     */
    private function migrateCorrections(): void
    {
        if (! Schema::hasTable('order_stock_corrections')) {
            return;
        }

        $seen = [];

        foreach (DB::table('order_stock_corrections')->orderBy('id')->get() as $row) {
            $key = $row->order_id . ':' . $row->product_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            DB::table('order_position_settings')->insert([
                'order_id'    => $row->order_id,
                'product_id'  => $row->product_id,
                'stores'      => json_encode([$row->store_id]),
                'stock_delta' => $row->delta,
                'stock_base'  => $row->moysklad_quantity,
                'note'        => $row->note,
                'user_id'     => $row->user_id,
                'created_at'  => $row->created_at,
                'updated_at'  => $row->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_position_settings');
    }
};
