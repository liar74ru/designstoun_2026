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
        Schema::table('product_stocks', function (Blueprint $table) {
            // Меняем тип поля available с integer на decimal(10, 3)
            // 10 - общее количество цифр, 3 - количество знаков после запятой
            $table->decimal('available', 10, 3)->default(0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            // Возвращаем обратно на integer
            $table->integer('available')->default(0)->change();
        });
    }
};
