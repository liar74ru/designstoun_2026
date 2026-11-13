<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отдел ↔ флажок-реквизит заказа покупателя в МойСклад — по id реквизита, а не по имени.
 * Заполнить здесь нельзя (нужен API): существующие отделы привязываются по имени при первой
 * синхронизации заявок (DepartmentAttributeService::autoLink).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->uuid('moysklad_attribute_id')->nullable()->unique()->after('default_production_store_id');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['moysklad_attribute_id']);
            $table->dropColumn('moysklad_attribute_id');
        });
    }
};
