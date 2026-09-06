<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Правила-модификаторы себестоимости отдела: бонусы и штрафы, правящие
 * коэффициент продукта при расчёте зарплаты пильщика и мастера.
 *
 * Заменяют захардкоженные флаги is_undercut / is_edging / is_small_tile и
 * глобальные ключи EDGING_COEFF / UNDERCUT_PENALTY / MASK_TILE_COEFF_BONUS.
 * Набор у каждого отдела свой, наследования нет (как у department_expenses):
 * отдел без правил ничего не прибавляет и не вычитает.
 *
 * Эффект задаётся ТОЛЬКО коэффициентами, не рублями: при подъёме базовой ставки
 * надбавки пересчитываются пропорционально сами, править их вручную не нужно.
 *
 * Порядок применения задаёт sort_order и он значим: правила применяются подряд,
 * `replace` обнуляет накопленное, `delta` прибавляет. Так торцовка отменяет
 * бонус маски, но не отменяет подкол — ровно как считала прежняя формула.
 *
 * Читать только через App\Support\ModifierEngine (кэш dept.{id}.modifiers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();

            // Стабильный слаг: по нему форма присылает сработавшие правила,
            // и по нему же сопоставляются старые булевы колонки.
            $table->string('key', 64);
            $table->string('name', 100);
            // HEX плашки; null — плашка не показывается.
            $table->string('color', 7)->nullable();

            // manual — чекбокс в форме, sku — срабатывает автоматически по маске.
            $table->string('trigger', 16);
            $table->string('sku_pattern', 32)->nullable();
            // Условие доступности ручного правила: маска SKU партии сырья.
            // Пусто — правило доступно всегда.
            $table->string('available_when_batch_sku', 32)->nullable();

            // reception | workshop | both
            $table->string('applies_to', 16)->default('both');

            // Эффект на роль: либо прибавка к коэффициенту, либо полная замена.
            $table->decimal('worker_coeff_delta', 8, 4)->nullable();
            $table->decimal('worker_coeff_replace', 8, 4)->nullable();
            $table->decimal('master_coeff_delta', 8, 4)->nullable();
            $table->decimal('master_coeff_replace', 8, 4)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // key — идентификатор правила внутри отдела, дубли недопустимы:
            // по нему формы присылают сработавшие правила.
            $table->unique(['department_id', 'key']);
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_modifiers');
    }
};
