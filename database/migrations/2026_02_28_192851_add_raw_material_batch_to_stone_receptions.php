<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stone_receptions', function (Blueprint $table) {
            $table->foreignId('raw_material_batch_id')->nullable()->after('product_id')
                ->constrained('raw_material_batches')->onDelete('set null');
            $table->decimal('raw_quantity_used', 10, 3)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stone_receptions', function (Blueprint $table) {
            $table->dropForeign(['raw_material_batch_id']);
            $table->dropColumn(['raw_material_batch_id', 'raw_quantity_used']);
        });
    }
};
