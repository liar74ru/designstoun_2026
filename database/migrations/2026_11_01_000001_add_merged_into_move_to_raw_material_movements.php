<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->boolean('merged_into_move')->default(false)->after('moysklad_synced');
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->dropColumn('merged_into_move');
        });
    }
};
