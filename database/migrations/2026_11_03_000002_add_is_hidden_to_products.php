<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Скрытый по умолчанию товар — флажок hiddenByDefault из МойСклад.
 * Такой товар не показывается в быстром поиске и дереве выбора, пока не включён
 * «весь каталог» / «Показать скрытые». В документах он остаётся как был.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
