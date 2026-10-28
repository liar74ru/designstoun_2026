<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Личная настройка работника: не показывать ему остаток копилки (вместо сумм — ***).
        Schema::table('workers', function (Blueprint $table) {
            $table->boolean('hide_piggy_balance')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('hide_piggy_balance');
        });
    }
};
