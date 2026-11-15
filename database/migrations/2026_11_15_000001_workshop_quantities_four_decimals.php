<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Количество в цехе и в шаблонах цеха — до 4 знаков после запятой (было 3).
 * Остатки тары (product_stocks) не трогаем: тара в штуках.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->resize(12, 4);
    }

    public function down(): void
    {
        $this->resize(10, 3);
    }

    private function resize(int $total, int $places): void
    {
        Schema::table('workshop_items', function (Blueprint $table) use ($total, $places) {
            $table->decimal('quantity', $total, $places)->change();
        });

        Schema::table('workshop_log_items', function (Blueprint $table) use ($total, $places) {
            $table->decimal('quantity_delta', $total, $places)->change();
        });

        Schema::table('workshop_logs', function (Blueprint $table) use ($total, $places) {
            $table->decimal('package_quantity_delta', $total, $places)->default(0)->change();
            $table->decimal('package_quantity_snapshot', $total, $places)->nullable()->change();
        });

        Schema::table('workshop_preset_items', function (Blueprint $table) use ($total, $places) {
            $table->decimal('quantity', $total, $places)->change();
        });
    }
};
