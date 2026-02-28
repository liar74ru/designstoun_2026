<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();

            // Связь с товаром (BIGINT INTEGER как в products)
            $table->unsignedBigInteger('product_id')->index();
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');

            // Связь со складом (UUID)
            $table->uuid('store_id')->index();
            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');

            // Данные остатков
            $table->integer('quantity')->default(0); // Количество в наличии
            $table->integer('reserved')->default(0); // Зарезервировано
            $table->integer('available')->default(0); // Доступно (quantity - reserved)
            $table->text('notes')->nullable(); // Примечания

            // История
            $table->timestamps();
            $table->softDeletes();

            // Уникальный индекс (товар + склад)
            $table->unique(['product_id', 'store_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_stocks');
    }
};
