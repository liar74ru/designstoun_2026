<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stone_reception_items', function (Blueprint $table) {
            $table->boolean('is_edging')->default(false)->after('is_undercut');
        });

        Schema::table('packaging_items', function (Blueprint $table) {
            $table->boolean('is_edging')->default(false)->after('is_undercut');
        });
    }

    public function down(): void
    {
        Schema::table('stone_reception_items', function (Blueprint $table) {
            $table->dropColumn('is_edging');
        });

        Schema::table('packaging_items', function (Blueprint $table) {
            $table->dropColumn('is_edging');
        });
    }
};
