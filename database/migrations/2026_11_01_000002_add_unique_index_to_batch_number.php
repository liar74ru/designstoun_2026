<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('raw_material_batches')->where('batch_number', '')->update(['batch_number' => null]);

        $duplicates = DB::table('raw_material_batches')
            ->whereNotNull('batch_number')
            ->groupBy('batch_number')
            ->havingRaw('count(*) > 1')
            ->pluck('batch_number');

        foreach ($duplicates as $number) {
            // Самая старая партия сохраняет номер, остальные получают свободный суффикс
            $ids = DB::table('raw_material_batches')->where('batch_number', $number)->orderBy('id')->pluck('id');

            foreach ($ids->slice(1) as $id) {
                $suffix = 0;
                do {
                    $candidate = $number . '_' . str_pad(++$suffix, 2, '0', STR_PAD_LEFT);
                } while (DB::table('raw_material_batches')->where('batch_number', $candidate)->exists());

                DB::table('raw_material_batches')->where('id', $id)->update(['batch_number' => $candidate]);

                Log::warning('Дубль номера партии переименован', ['batch_id' => $id, 'old' => $number, 'new' => $candidate]);
            }
        }

        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->unique('batch_number');
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->dropUnique(['batch_number']);
        });
    }
};
