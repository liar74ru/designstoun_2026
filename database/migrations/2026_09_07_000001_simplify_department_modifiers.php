<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Упрощение модели правил себестоимости: остаются только слагаемые.
 *
 * Правила задумывались как повторение прежней захардкоженной формулы, поэтому
 * у каждой роли было два эффекта — «прибавить» и «заменить» — и порядок
 * применения, от которого зависел результат. Итоговая модель проще:
 *
 *     коэффициент = коэффициент продукта + сумма всех сработавших правил
 *
 * Сложение коммутативно, поэтому sort_order не нужен, а замена не выражается
 * в этой модели вовсе.
 *
 * Значение замены переносится в слагаемое, чтобы число, заданное админом, не
 * потерялось. Смысл у него при этом меняется: торцовка была плоскими −2.5 при
 * любом продукте, а становится «−2.5 к коэффициенту продукта». Правильное
 * значение задаётся в карточке отдела.
 *
 * Снапшоты позиций правятся так же, но уже посчитанные ставки
 * (worker_cost_per_m2 / master_cost_per_m2) не пересчитываются — выплаченные
 * зарплаты не переписываем.
 *
 * down() возвращает колонки, но не семантику: какие из значений были заменой,
 * после переноса неизвестно. Это принципиально невозможно.
 */
return new class extends Migration
{
    /** Таблицы с эффектами правил: правила отдела и их снапшот на позиции. */
    private const TABLES = ['department_modifiers', 'production_item_modifiers'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            foreach (['worker', 'master'] as $role) {
                DB::table($table)
                    ->whereNull("{$role}_coeff_delta")
                    ->whereNotNull("{$role}_coeff_replace")
                    ->update([
                        "{$role}_coeff_delta" => DB::raw("{$role}_coeff_replace"),
                    ]);
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['worker_coeff_replace', 'master_coeff_replace', 'sort_order']);
            });
        }

        $this->forgetCaches();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('worker_coeff_replace', 8, 4)->nullable()->after('worker_coeff_delta');
                $t->decimal('master_coeff_replace', 8, 4)->nullable()->after('master_coeff_delta');
                $t->unsignedSmallInteger('sort_order')->default(0);
            });
        }

        $this->forgetCaches();
    }

    /** Ключи кэша литеральные: Department::modifiersCacheKey() может измениться. */
    private function forgetCaches(): void
    {
        foreach (DB::table('departments')->pluck('id') as $departmentId) {
            Cache::forget("dept.{$departmentId}.modifiers");
        }
    }
};
