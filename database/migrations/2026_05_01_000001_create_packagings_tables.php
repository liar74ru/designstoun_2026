<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Сессия упаковки. Аналог stone_receptions, но без партии сырья:
        // упаковщик берёт готовую продукцию и укладывает её в тару (Product 07-03-xx).
        Schema::create('packagings', function (Blueprint $table) {
            $table->id();

            // Упаковщик (кто выполнял упаковку)
            $table->foreignId('packer_id')
                ->constrained('workers')
                ->onDelete('restrict');

            // Кто внёс операцию (мастер/админ)
            $table->foreignId('receiver_id')
                ->constrained('workers')
                ->onDelete('restrict');

            // Склад производства (берётся из department.default_production_store_id)
            $table->uuid('store_id');
            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('restrict');

            // Тип тары (Product с SKU 07-03-xx)
            $table->foreignId('package_product_id')
                ->constrained('products')
                ->onDelete('restrict');

            // Количество тары (шт)
            $table->decimal('package_quantity', 10, 3)->default(0);

            $table->text('notes')->nullable();

            // active | completed | error
            $table->string('status')->default('active');

            // Поля синхронизации с МойСклад (паттерн из docs/moysklad-sync-pattern.md)
            $table->string('moysklad_processing_id')->nullable();
            $table->string('moysklad_processing_name')->nullable();
            $table->string('moysklad_sync_status')->nullable();
            $table->text('moysklad_sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index('packer_id');
            $table->index('store_id');
            $table->index('status');
        });

        // Позиции упаковки — упакованные продукты (аналог stone_reception_items).
        Schema::create('packaging_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('packaging_id')
                ->constrained('packagings')
                ->onDelete('cascade');

            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict');

            $table->decimal('quantity', 10, 3);

            $table->decimal('effective_cost_coeff', 8, 4)->nullable();
            $table->boolean('is_undercut')->default(false);
            $table->boolean('is_small_tile')->default(false);
            $table->decimal('worker_cost_per_m2', 10, 2)->nullable();
            $table->decimal('master_cost_per_m2', 10, 2)->nullable();

            $table->timestamps();

            $table->index('packaging_id');
            $table->index('product_id');
            $table->unique(['packaging_id', 'product_id'], 'packaging_product_unique');
        });

        // Журнал упаковок (аналог reception_logs).
        Schema::create('packaging_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('packaging_id')
                ->constrained('packagings')
                ->onDelete('cascade');

            $table->foreignId('packer_id')
                ->nullable()
                ->constrained('workers')
                ->onDelete('set null');

            $table->foreignId('receiver_id')
                ->constrained('workers')
                ->onDelete('restrict');

            // 'created' — создание, 'updated' — правка
            $table->string('type')->default('created');

            // Дельта количества тары (положительная = добавили, отрицательная = убрали)
            $table->decimal('package_quantity_delta', 10, 3)->default(0);

            // Снимок количества тары на момент лога (после применения дельты)
            $table->decimal('package_quantity_snapshot', 10, 3)->nullable();

            $table->timestamps();

            $table->index('packaging_id');
            $table->index('packer_id');
            $table->index('created_at');
        });

        // Дельты по продуктам в упаковке (аналог reception_log_items).
        Schema::create('packaging_log_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('packaging_log_id')
                ->constrained('packaging_logs')
                ->onDelete('cascade');

            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict');

            $table->decimal('quantity_delta', 10, 3);

            $table->timestamps();

            $table->index('packaging_log_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_log_items');
        Schema::dropIfExists('packaging_logs');
        Schema::dropIfExists('packaging_items');
        Schema::dropIfExists('packagings');
    }
};
