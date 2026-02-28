<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stone_receptions', function (Blueprint $table) {
            $table->id();

            // Приемщик (BIGINT - из таблицы workers)
            $table->foreignId('receiver_id')
                ->constrained('workers')
                ->onDelete('restrict');

            // Пильщик (BIGINT - из таблицы workers)
            $table->foreignId('cutter_id')
                ->nullable()
                ->constrained('workers')
                ->onDelete('set null');

            // Продукт (BIGINT - из таблицы products)
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict');

            // Склад (UUID - из таблицы stores)
            $table->uuid('store_id');
            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('restrict');

            // Данные приемки
            $table->decimal('quantity', 10, 3);
            $table->text('notes')->nullable();

            $table->timestamps();

            // Индексы для быстрого поиска
            $table->index('created_at');
            $table->index(['receiver_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stone_receptions');
    }
};
