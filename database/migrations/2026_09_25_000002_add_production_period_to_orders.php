<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Окно производства заявки. Истории статусов в проекте нет, а «изготовлено, пока
        // заявка была в производстве» считается именно по этому окну.
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('production_started_at')->nullable()->after('moment')
                ->comment('Вход в производственный статус');
            $table->timestamp('production_ended_at')->nullable()->after('production_started_at')
                ->comment('Выход из производственного статуса; null — ещё в производстве');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['production_started_at', 'production_ended_at']);
        });
    }
};
