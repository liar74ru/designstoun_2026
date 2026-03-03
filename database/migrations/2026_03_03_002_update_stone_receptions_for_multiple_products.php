<?php
// database/migrations/xxxx_xx_xx_xxxxxx_recreate_stone_receptions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Удаляем существующие таблицы если они есть (в правильном порядке)
        Schema::dropIfExists('stone_reception_items');
        Schema::dropIfExists('stone_receptions');

        // Создаем таблицу приемок заново
        Schema::create('stone_receptions', function (Blueprint $table) {
            $table->id();

            // Приемщик (кто принимает готовую продукцию)
            $table->foreignId('receiver_id')
                ->constrained('workers')
                ->onDelete('restrict');

            // Пильщик (кто производил работу)
            $table->foreignId('cutter_id')
                ->nullable()
                ->constrained('workers')
                ->onDelete('set null');

            // Склад (куда кладем готовую продукцию)
            $table->uuid('store_id');
            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('restrict');

            // Связь с партией сырья
            $table->foreignId('raw_material_batch_id')
                ->nullable()
                ->constrained('raw_material_batches')
                ->onDelete('set null');

            // Сколько сырья израсходовано на эту приемку (в м³)
            $table->decimal('raw_quantity_used', 10, 3)
                ->default(0);

            // Примечания
            $table->text('notes')->nullable();

            $table->timestamps();

            // Индексы для быстрого поиска
            $table->index('created_at');
            $table->index('receiver_id');
            $table->index('cutter_id');
            $table->index('store_id');
            $table->index('raw_material_batch_id');
        });

        // Создаем таблицу для позиций приемки (продукты)
        Schema::create('stone_reception_items', function (Blueprint $table) {
            $table->id();

            // Связь с приемкой
            $table->foreignId('stone_reception_id')
                ->constrained('stone_receptions')
                ->onDelete('cascade');

            // Продукт (готовая продукция)
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict');

            // Количество готовой продукции (в м²)
            $table->decimal('quantity', 10, 3);

            $table->timestamps();

            // Индексы
            $table->index('stone_reception_id');
            $table->index('product_id');

            // Составной уникальный ключ (на всякий случай, чтобы не было дублей продукта в одной приемке)
            $table->unique(['stone_reception_id', 'product_id'], 'reception_product_unique');
        });
    }

    public function down(): void
    {
        // Восстанавливаем старую структуру (если нужно откатить миграцию)
        Schema::dropIfExists('stone_reception_items');
        Schema::dropIfExists('stone_receptions');

        // Создаем старую версию таблицы
        Schema::create('stone_receptions', function (Blueprint $table) {
            $table->id();

            // Приемщик
            $table->foreignId('receiver_id')
                ->constrained('workers')
                ->onDelete('restrict');

            // Пильщик
            $table->foreignId('cutter_id')
                ->nullable()
                ->constrained('workers')
                ->onDelete('set null');

            // Продукт (в старой версии только один)
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict');

            // Склад
            $table->uuid('store_id');
            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('restrict');

            // Количество
            $table->decimal('quantity', 10, 3);

            // Примечания
            $table->text('notes')->nullable();

            $table->timestamps();

            // Индексы
            $table->index('created_at');
            $table->index(['receiver_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });
    }
};
