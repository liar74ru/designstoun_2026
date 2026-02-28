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
        Schema::table('products', function (Blueprint $table) {
            // Добавляем поле code после sku
            $table->string('code')->nullable()->after('sku')->comment('Код товара из МойСклад');

            // Добавляем индекс для поля code
            $table->index('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Удаляем индекс
            $table->dropIndex(['code']);

            // Удаляем поле
            $table->dropColumn('code');
        });
    }
};
