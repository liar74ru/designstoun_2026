<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Меняем тип с integer на decimal (10, 3) - 10 цифр всего, 3 после запятой
            $table->decimal('quantity', 10, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Возвращаем обратно на integer
            $table->integer('quantity')->default(0)->change();
        });
    }
};
