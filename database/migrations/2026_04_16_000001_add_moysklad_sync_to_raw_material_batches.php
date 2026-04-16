<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->string('moysklad_processing_id')->nullable()->after('notes');
            $table->string('moysklad_processing_name')->nullable()->after('moysklad_processing_id');
            $table->string('moysklad_sync_status')->nullable()->after('moysklad_processing_name');
            $table->text('moysklad_sync_error')->nullable()->after('moysklad_sync_status');
            $table->timestamp('synced_at')->nullable()->after('moysklad_sync_error');
        });

        // Все существующие партии считаются синхронизированными
        DB::table('raw_material_batches')->update(['moysklad_sync_status' => 'synced']);
    }

    public function down(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->dropColumn([
                'moysklad_processing_id',
                'moysklad_processing_name',
                'moysklad_sync_status',
                'moysklad_sync_error',
                'synced_at',
            ]);
        });
    }
};
