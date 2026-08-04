<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Setting;
use App\Models\StoneReceptionItem;

/**
 * Формула ставки мастера заинлайнена намеренно: миграция фиксирует расчёт,
 * действовавший на момент её написания, и не должна ломаться при эволюции
 * StoneReceptionItem::computeMasterCost().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stone_reception_items', function (Blueprint $table) {
            $table->boolean('is_small_tile')->default(false)->after('is_undercut');
            $table->decimal('master_cost_per_m2', 10, 2)->nullable()->after('worker_cost_per_m2');
        });

        StoneReceptionItem::with('product')->chunkById(200, function ($items) {
            foreach ($items as $item) {
                $isSmallTile = StoneReceptionItem::skuIsSmallTile($item->product?->sku);
                $item->is_small_tile      = $isSmallTile;
                $item->master_cost_per_m2 = (float) Setting::get('MASTER_BASE_RATE', 100)
                    + ($item->is_undercut ? (float) Setting::get('MASTER_UNDERCUT_RATE', 50) : 0)
                    + ($isSmallTile ? (float) Setting::get('MASTER_SMALL_TILE_RATE', 50) : 0);
                $item->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stone_reception_items', function (Blueprint $table) {
            $table->dropColumn(['is_small_tile', 'master_cost_per_m2']);
        });
    }
};
