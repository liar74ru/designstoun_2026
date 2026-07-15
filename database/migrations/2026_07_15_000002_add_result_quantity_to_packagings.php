<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packagings', function (Blueprint $table) {
            // Количество товара-результата. NULL — фолбэк на package_quantity (старые упаковки).
            $table->decimal('result_quantity', 10, 3)
                ->nullable()
                ->after('result_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('packagings', function (Blueprint $table) {
            $table->dropColumn('result_quantity');
        });
    }
};
