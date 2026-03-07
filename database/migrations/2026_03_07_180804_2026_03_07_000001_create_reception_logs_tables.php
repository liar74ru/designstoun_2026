<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Журнал приёмок.
        // Каждая запись — это либо первичная приёмка (type='created'),
        // либо дельта при редактировании (type='updated').
        // raw_quantity_delta нужна для аналитики эффективности:
        // сколько готовой продукции получено с единицы сырья.
        Schema::create('reception_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stone_reception_id')
                ->constrained('stone_receptions')
                ->onDelete('cascade');

            $table->foreignId('raw_material_batch_id')
                ->nullable()
                ->constrained('raw_material_batches')
                ->onDelete('set null');

            $table->foreignId('cutter_id')
                ->nullable()
                ->constrained('workers')
                ->onDelete('set null');

            $table->foreignId('receiver_id')
                ->constrained('workers')
                ->onDelete('restrict');

            // 'created' — первичная приёмка, 'updated' — правка
            $table->string('type')->default('created');

            // Дельта расхода сырья: положительная = списали, отрицательная = вернули
            $table->decimal('raw_quantity_delta', 10, 3)->default(0);

            $table->timestamps();

            $table->index('stone_reception_id');
            $table->index('cutter_id');
            $table->index('raw_material_batch_id');
            $table->index('created_at');
        });

        // Продукты лога — дельта количества по каждому продукту.
        // Отрицательная дельта = продукт убавили или совсем удалили из приёмки.
        Schema::create('reception_log_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reception_log_id')
                ->constrained('reception_logs')
                ->onDelete('cascade');

            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict');

            $table->decimal('quantity_delta', 10, 3);

            $table->timestamps();

            $table->index('reception_log_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_log_items');
        Schema::dropIfExists('reception_logs');
    }
};
