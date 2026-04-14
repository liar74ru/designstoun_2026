<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->dropColumn(['moysklad_processing_id', 'moysklad_processing_name', 'moysklad_sync_error']);
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->string('moysklad_processing_id')->nullable()->after('batch_number');
            $table->string('moysklad_processing_name')->nullable()->after('moysklad_processing_id');
            $table->text('moysklad_sync_error')->nullable()->after('moysklad_processing_name');
        });
    }
};
