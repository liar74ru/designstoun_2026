<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Переработка модуля «Цех»: единая таблица workshop_items с ролью строки
 * (raw — сырьё, package — упаковка/тара, product — продукт на выходе) вместо
 * одиночных колонок тары/результата на workshops. Плюс ручные затраты на
 * производство (manual_processing_sum, ₽/ед продукта) с автофоллбэком в сервисе.
 *
 * Согласовано: fresh — существующие операции не переносятся (backfill не делаем).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_items', function (Blueprint $table) {
            $table->string('role')->default('raw')->after('product_id')->index();
        });

        Schema::table('workshop_log_items', function (Blueprint $table) {
            $table->string('role')->default('raw')->after('product_id');
        });

        Schema::table('workshops', function (Blueprint $table) {
            // Ручные затраты на производство единицы продукта (руб). NULL → автофоллбэк.
            $table->decimal('manual_processing_sum', 10, 2)->nullable()->after('notes');
        });

        // Один и тот же товар теперь может встречаться в разных ролях (сырьё и продукт).
        // Старый индекс сохранил имя packaging_product_unique (переименование не меняло его).
        Schema::table('workshop_items', function (Blueprint $table) {
            $table->dropUnique('packaging_product_unique');
            $table->unique(['workshop_id', 'product_id', 'role'], 'workshop_item_role_unique');
        });

        // Одиночные колонки тары/результата больше не нужны — всё в workshop_items.
        // На PostgreSQL FK-констрейнты сохранили имена времён `packagings` (переименование
        // таблицы не меняет имена констрейнтов) — дропаем их явно перед удалением колонок.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            foreach ([
                'packagings_package_product_id_foreign',
                'workshops_package_product_id_foreign',
                'packagings_result_product_id_foreign',
                'workshops_result_product_id_foreign',
            ] as $constraint) {
                DB::statement("ALTER TABLE workshops DROP CONSTRAINT IF EXISTS \"{$constraint}\"");
            }

            Schema::table('workshops', function (Blueprint $table) {
                $table->dropColumn(['package_product_id', 'result_product_id', 'result_quantity', 'package_quantity']);
            });
        } else {
            Schema::table('workshops', function (Blueprint $table) {
                $table->dropConstrainedForeignId('package_product_id');
                $table->dropConstrainedForeignId('result_product_id');
                $table->dropColumn(['result_quantity', 'package_quantity']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->foreignId('package_product_id')->nullable()->after('department_id')
                ->constrained('products')->onDelete('restrict');
            $table->decimal('package_quantity', 10, 3)->default(0)->after('package_product_id');
            $table->foreignId('result_product_id')->nullable()->after('package_quantity')
                ->constrained('products')->onDelete('restrict');
            $table->decimal('result_quantity', 10, 3)->nullable()->after('result_product_id');
            $table->dropColumn('manual_processing_sum');
        });

        Schema::table('workshop_items', function (Blueprint $table) {
            $table->dropUnique('workshop_item_role_unique');
            $table->unique(['workshop_id', 'product_id'], 'packaging_product_unique');
            $table->dropColumn('role');
        });

        Schema::table('workshop_log_items', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
