<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stone_receptions', function (Blueprint $table) {
            $table->string('moysklad_sync_status')->nullable()->after('moysklad_processing_id');
            $table->text('moysklad_sync_error')->nullable()->after('moysklad_sync_status');
            $table->string('moysklad_processing_name')->nullable()->after('moysklad_sync_error');
        });

        // Копируем данные из raw_material_batches в stone_receptions
        DB::table('raw_material_batches')
            ->whereNotNull('moysklad_processing_id')
            ->orderBy('id')
            ->each(function ($batch) {
                $syncError  = $batch->moysklad_sync_error ?: null;
                $syncStatus = $syncError ? 'not_synced' : 'synced';

                DB::table('stone_receptions')
                    ->where('raw_material_batch_id', $batch->id)
                    ->update([
                        'moysklad_processing_id'   => $batch->moysklad_processing_id,
                        'moysklad_processing_name' => $batch->moysklad_processing_name,
                        'moysklad_sync_error'      => $syncError,
                        'moysklad_sync_status'     => $syncStatus,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('stone_receptions', function (Blueprint $table) {
            $table->dropColumn(['moysklad_sync_status', 'moysklad_sync_error', 'moysklad_processing_name']);
        });
    }
};
