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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique()->nullable(); // Артикул
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('old_price', 10, 2)->nullable(); // Старая цена
            $table->integer('quantity')->default(0); // Количество на складе
            $table->string('moysklad_id')->nullable()->unique(); // ID из МойСклад
            $table->boolean('is_active')->default(true);
            $table->json('attributes')->nullable(); // Дополнительные атрибуты
            $table->timestamps();
            $table->softDeletes(); // Для мягкого удаления
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
