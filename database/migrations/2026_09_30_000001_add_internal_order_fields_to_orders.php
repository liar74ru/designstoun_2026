<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Внутренние заказы между отделами живут в той же таблице, что и заявки покупателей.
 *
 * - kind — 'customer' (из МойСклад) или 'internal' (создан в программе);
 * - uuid — ключ ссылок: у заявок покупателей равен moysklad_id, у внутренних — свой,
 *   поэтому moysklad_id становится nullable;
 * - parent_uuid — заявка-основание. Без FK: заявка, выпавшая из выгрузки, удаляется
 *   синком и при возврате пересоздаётся с новым id, а uuid у неё тот же.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('moysklad_id')->nullable()->change();
            $table->string('uuid')->nullable()->after('id');
            $table->string('kind', 20)->default('customer')->after('uuid')->index();
            $table->foreignId('customer_department_id')->nullable()->after('kind')
                ->constrained('departments')->nullOnDelete();
            $table->string('parent_uuid')->nullable()->after('customer_department_id')->index();
            $table->string('parent_order_name')->nullable()->after('parent_uuid');
            $table->foreignId('created_by_user_id')->nullable()->after('parent_order_name')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('orders')->update(['uuid' => DB::raw('moysklad_id')]);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('uuid')->nullable(false)->change();
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        DB::table('orders')->where('kind', 'internal')->delete();

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropForeign(['customer_department_id']);
            $table->dropForeign(['created_by_user_id']);
            $table->dropIndex(['kind']);
            $table->dropIndex(['parent_uuid']);
            $table->dropColumn([
                'uuid', 'kind', 'customer_department_id', 'parent_uuid',
                'parent_order_name', 'created_by_user_id',
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('moysklad_id')->nullable(false)->change();
        });
    }
};
