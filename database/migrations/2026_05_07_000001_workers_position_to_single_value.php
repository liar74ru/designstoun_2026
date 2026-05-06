<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $t) {
            $t->string('position', 64)->nullable()->after('id');
        });

        foreach (DB::table('workers')->get(['id', 'positions']) as $w) {
            $arr = is_string($w->positions)
                ? (json_decode($w->positions, true) ?: [])
                : (array) ($w->positions ?? []);
            DB::table('workers')->where('id', $w->id)->update(['position' => $arr[0] ?? null]);
        }

        Schema::table('workers', function (Blueprint $t) {
            $t->dropColumn('positions');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $t) {
            $t->json('positions')->nullable()->after('id');
        });

        foreach (DB::table('workers')->get(['id', 'position']) as $w) {
            $arr = $w->position ? [$w->position] : [];
            DB::table('workers')->where('id', $w->id)->update(['positions' => json_encode($arr)]);
        }

        Schema::table('workers', function (Blueprint $t) {
            $t->dropColumn('position');
        });
    }
};
