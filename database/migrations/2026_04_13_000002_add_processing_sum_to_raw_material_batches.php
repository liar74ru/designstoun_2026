<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\RawMaterialBatch;
use App\Models\Setting;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->decimal('processing_sum', 10, 2)->nullable()->after('moysklad_sync_error');
        });

        // Заполняем для всех существующих партий текущим значением накладных расходов
        $keys = [
            'BLADE_WEAR', 'RECEPTION_COST', 'PACKAGING_COST', 'WASTE_REMOVAL',
            'ELECTRICITY', 'PPE_COST', 'FORKLIFT_COST', 'MACHINE_COST',
            'RENT_COST', 'OTHER_COSTS',
        ];
        $processingSum = (float) array_sum(array_map(
            fn ($key) => (float) Setting::get($key, 0),
            $keys
        ));

        RawMaterialBatch::chunkById(200, function ($batches) use ($processingSum) {
            foreach ($batches as $batch) {
                $batch->processing_sum = $processingSum;
                $batch->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->dropColumn('processing_sum');
        });
    }
};
