<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->string('moysklad_move_id')->nullable()->after('movement_type');
            $table->boolean('moysklad_synced')->default(false)->after('moysklad_move_id');
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->dropColumn(['moysklad_move_id', 'moysklad_synced']);
        });
    }
};
