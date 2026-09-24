<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Поправка к остатку в разрезе заявки: product_stocks — кэш МойСклад и перезаписывается
        // синхронизацией, поэтому фактический остаток храним отдельно и накладываем при выводе.
        // Привязка к product_id, а не к order_item_id: позиции заявки пересоздаются при каждой
        // синхронизации (CustomerOrderSyncService::upsertOrder).
        Schema::create('order_stock_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $t->foreignUuid('store_id')->constrained('stores')->cascadeOnDelete();
            $t->decimal('delta', 12, 3)->comment('Поправка к остатку, может быть отрицательной');
            $t->decimal('moysklad_quantity', 12, 3)->comment('Остаток МойСклад на момент уточнения');
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['order_id', 'product_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_stock_corrections');
    }
};
