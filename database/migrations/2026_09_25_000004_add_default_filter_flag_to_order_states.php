<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Какие статусы подставлять в фильтр списка заявок при заходе без параметров.
        // Отдельно от is_enabled: подгружать из МойСклад и показывать по умолчанию — разное.
        Schema::table('order_states', function (Blueprint $table) {
            $table->boolean('is_default_filter')->default(false)->after('is_production');
        });
    }

    public function down(): void
    {
        Schema::table('order_states', function (Blueprint $table) {
            $table->dropColumn('is_default_filter');
        });
    }
};
