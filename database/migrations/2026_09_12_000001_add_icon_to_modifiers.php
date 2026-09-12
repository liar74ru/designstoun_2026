<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Иконка правила себестоимости.
 *
 * До перехода на правила подкол рисовался молнией, а торцовка ножницами —
 * признак читался в списке с одного взгляда. У правила отдела иконки не было,
 * поэтому в карточках оставался только цвет. Иконку выбирает админ из пресета
 * DepartmentModifier::ICONS, как и цвет.
 *
 * Колонка заводится и в снапшоте позиции: правка правила задним числом не
 * должна перекрашивать уже посчитанные документы — тот же принцип, что у
 * name/color.
 *
 * Бэкфил идёт по собственной замороженной копии значений: миграция обязана
 * оставаться самодостаточной и не смотреть в config/production_modifiers.php.
 */
return new class extends Migration
{
    /** Правила отдела и их снапшот на позиции. */
    private const TABLES = ['department_modifiers', 'production_item_modifiers'];

    /** Иконки стандартных правил на момент миграции. */
    private const ICONS = [
        'undercut'   => 'bi-lightning-charge-fill',
        'edging'     => 'bi-scissors',
        'small_tile' => 'bi-grid-3x3',
        'mask_tile'  => 'bi-mask',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('icon', 40)->nullable()->after('color');
            });

            foreach (self::ICONS as $key => $icon) {
                DB::table($table)->where('key', $key)->update(['icon' => $icon]);
            }
        }

        $this->forgetCaches();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('icon');
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
