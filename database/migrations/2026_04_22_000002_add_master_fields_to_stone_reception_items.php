<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\StoneReceptionItem;

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
                $item->master_cost_per_m2 = StoneReceptionItem::computeMasterCost(
                    (bool) $item->is_undercut,
                    $isSmallTile,
                );
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
