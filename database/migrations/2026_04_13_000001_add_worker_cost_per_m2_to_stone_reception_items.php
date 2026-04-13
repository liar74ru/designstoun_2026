<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\StoneReceptionItem;
use App\Models\Setting;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stone_reception_items', function (Blueprint $table) {
            $table->decimal('worker_cost_per_m2', 10, 2)->nullable()->after('effective_cost_coeff');
        });

        // Заполняем для существующих записей по формуле prodCost:
        // ROUNDDOWN((rate + rate * 17% * coeff) / 10) * 10
        $rate = (float) Setting::get('PIECE_RATE', 390);

        StoneReceptionItem::chunkById(200, function ($items) use ($rate) {
            foreach ($items as $item) {
                $coeff   = (float) $item->effective_cost_coeff;
                $perUnit = $rate + ($rate * 0.17) * $coeff;
                $rounded = floor($perUnit / 10) * 10;
                $item->worker_cost_per_m2 = $rounded;
                $item->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stone_reception_items', function (Blueprint $table) {
            $table->dropColumn('worker_cost_per_m2');
        });
    }
};
