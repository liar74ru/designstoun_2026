<?php
// database/migrations/xxxx_xx_xx_xxxxxx_add_status_to_stone_receptions.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stone_receptions', function (Blueprint $table) {
            $table->string('moysklad_processing_id')->nullable()->after('notes');
            $table->string('status')->default('active')->after('moysklad_processing_id');
            $table->timestamp('synced_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('stone_receptions', function (Blueprint $table) {
            $table->dropColumn(['moysklad_processing_id', 'status', 'synced_at']);
        });
    }
};
