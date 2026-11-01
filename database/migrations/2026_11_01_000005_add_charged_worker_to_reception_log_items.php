<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Минус за правку приёмки — тому, кто получил плюс. Запись журнала остаётся за тем, кто правил,
    // поэтому получатель хранится на строке; null — приёмщик записи, старые строки не меняются.
    public function up(): void
    {
        Schema::table('reception_log_items', function (Blueprint $table) {
            $table->foreignId('charged_worker_id')->nullable()->after('quantity_delta')
                ->constrained('workers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reception_log_items', function (Blueprint $table) {
            $table->dropForeign(['charged_worker_id']);
            $table->dropColumn('charged_worker_id');
        });
    }
};
