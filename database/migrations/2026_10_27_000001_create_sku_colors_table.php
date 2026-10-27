<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_colors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->nullable();
            $table->char('color', 7);
            // Префиксы SKU одного цвета: ["01-07", "04-07"]. Уникальность между строками — SkuColorRequest.
            $table->json('prefixes');
            $table->timestamps();
        });

        // Набор, который до этой миграции был захардкожен в Product::SKU_COLORS.
        // Литералами: модель со временем меняется, а старая миграция ломаться не должна.
        $stones = [
            '01' => ['Кварцит',         '#BFBFBF'],
            '02' => ['Серицит',         '#F4B084'],
            '04' => ['Кора дерева',     '#FFC000'],
            '06' => ['Златолит',        '#FFFF00'],
            '07' => ['Сланец зелёный',  '#92D050'],
            '08' => ['Чёрный сланец',   '#000000'],
            '09' => ['Кварцит чёрный',  '#BF8F00'],
            '10' => ['Златолит зелёный', '#E2EFDA'],
        ];

        $now  = now();
        $rows = [];
        foreach ($stones as $code => [$name, $color]) {
            $rows[] = [
                'name'       => $name,
                'color'      => $color,
                'prefixes'   => json_encode(["01-{$code}", "04-{$code}"]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('sku_colors')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_colors');
    }
};
