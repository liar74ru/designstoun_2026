<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SkuColor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SkuColorService
{
    public function all(): Collection
    {
        return SkuColor::orderBy('id')->get();
    }

    /**
     * Полная замена списка цветов (replace-all).
     *
     * $rows — провалидированный ввод формы: [['name' => ?string, 'color' => string, 'prefixes' => string[]], …].
     */
    public function sync(array $rows): void
    {
        $prepared = array_map(fn (array $row) => [
            'name'     => trim((string) ($row['name'] ?? '')) ?: null,
            'color'    => strtoupper($row['color']),
            'prefixes' => array_values($row['prefixes']),
        ], $rows);

        DB::transaction(function () use ($prepared) {
            SkuColor::query()->delete();

            foreach ($prepared as $row) {
                SkuColor::create($row);
            }
        });

        SkuColor::forgetCache();
        Product::forgetSkuColorMemo();
    }
}
