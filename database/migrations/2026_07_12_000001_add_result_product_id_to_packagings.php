<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packagings', function (Blueprint $table) {
            // Товар-результат упаковки. NULL — приходуются те же продукты (режим цеха);
            // задан — в МойСклад приходуется этот товар в количестве package_quantity.
            $table->foreignId('result_product_id')
                ->nullable()
                ->after('package_quantity')
                ->constrained('products')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('packagings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('result_product_id');
        });
    }
};
