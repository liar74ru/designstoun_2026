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
            // Меняем тип поля quantity с integer на decimal(10, 3)
            $table->decimal('quantity', 10, 3)->default(0)->change();

            // Меняем тип поля reserved с integer на decimal(10, 3)
            $table->decimal('reserved', 10, 3)->default(0)->change();

            // Меняем тип поля in_transit с integer на decimal(10, 3)
            $table->decimal('in_transit', 10, 3)->default(0)->change();

            // Меняем тип поля available с integer на decimal(10, 3)
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
            $table->integer('quantity')->default(0)->change();
            $table->integer('reserved')->default(0)->change();
            $table->integer('in_transit')->default(0)->change();
            $table->integer('available')->default(0)->change();
        });
    }
};
