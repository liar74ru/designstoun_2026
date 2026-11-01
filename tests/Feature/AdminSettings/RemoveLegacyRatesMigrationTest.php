<?php

use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Удаление старых флагов и глобальных коэффициентов (A2, 4.0.5). Снимок правил
 * production_item_modifiers остаётся единственным источником.
 */

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_11_01_000003_remove_legacy_rate_flags_and_settings.php');
});

test('колонки флагов удалены у приёмки и цеха, is_small_tile остался', function (string $table) {
    expect(Schema::hasColumns($table, ['is_undercut']))->toBeFalse()
        ->and(Schema::hasColumns($table, ['is_edging']))->toBeFalse()
        ->and(Schema::hasColumn($table, 'is_small_tile'))->toBeTrue();
})->with(['stone_reception_items', 'workshop_items']);

test('удаляет только старые ключи настроек и настроек отдела', function () {
    $dept = Department::create(['name' => 'Резка', 'is_active' => true]);

    $this->migration->down();

    foreach (['UNDERCUT_PENALTY', 'EDGING_COEFF', 'MASK_TILE_COEFF_BONUS', 'MASTER_UNDERCUT_RATE', 'PIECE_RATE', 'MOYSKLAD_DONE_STATE'] as $key) {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => '1']);
    }
    foreach (['MASTER_UNDERCUT_RATE', 'PIECE_RATE'] as $key) {
        DB::table('department_settings')->insert(['department_id' => $dept->id, 'key' => $key, 'value' => '1']);
    }

    $this->migration->up();

    expect(DB::table('settings')->whereIn('key', ['UNDERCUT_PENALTY', 'EDGING_COEFF', 'MASK_TILE_COEFF_BONUS', 'MASTER_UNDERCUT_RATE'])->count())->toBe(0)
        ->and(DB::table('settings')->whereIn('key', ['PIECE_RATE', 'MOYSKLAD_DONE_STATE'])->count())->toBe(2)
        ->and(DB::table('department_settings')->pluck('key')->all())->toBe(['PIECE_RATE']);
});

test('down возвращает колонки со значением по умолчанию', function () {
    $this->migration->down();

    expect(Schema::hasColumns('stone_reception_items', ['is_undercut', 'is_edging']))->toBeTrue()
        ->and(Schema::hasColumns('workshop_items', ['is_undercut', 'is_edging']))->toBeTrue();

    $this->migration->up();
});
