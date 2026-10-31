<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Устаревшей позиции нет в Worker::POSITIONS и в реестре операций:
        // такой работник не видел свой дашборд (403 на /my-work).
        DB::table('workers')
            ->where('position', 'Пильщик')
            ->update(['position' => 'Работник']);
    }

    public function down(): void
    {
        // После слияния с «Работник» исходные значения не восстановить.
    }
};
