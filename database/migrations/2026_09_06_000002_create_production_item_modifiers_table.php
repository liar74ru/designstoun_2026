<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снапшот правил, применённых к позиции документа в момент её создания.
 *
 * Зачем копия, а не ссылка: правка правила задним числом не должна переписывать
 * уже выплаченные зарплаты. Тот же принцип, по которому в позиции заморожены
 * worker_cost_per_m2 / master_cost_per_m2.
 *
 * Позиция принадлежит либо приёмке, либо цеху — заполнена ровно одна из двух FK.
 * Полиморфных связей в проекте нет ни одной, новый паттерн намеренно не вводим.
 *
 * department_modifier_id — nullOnDelete: удаление правила в настройках отдела
 * не должно уносить историю, снапшот остаётся самодостаточным.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_item_modifiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stone_reception_item_id')->nullable()
                ->constrained('stone_reception_items')->cascadeOnDelete();
            $table->foreignId('workshop_item_id')->nullable()
                ->constrained('workshop_items')->cascadeOnDelete();

            $table->foreignId('department_modifier_id')->nullable()
                ->constrained('department_modifiers')->nullOnDelete();

            // Копия правила на момент применения — читается вместо связи.
            $table->string('key', 64);
            $table->string('name', 100);
            $table->string('color', 7)->nullable();

            $table->decimal('worker_coeff_delta', 8, 4)->nullable();
            $table->decimal('worker_coeff_replace', 8, 4)->nullable();
            $table->decimal('master_coeff_delta', 8, 4)->nullable();
            $table->decimal('master_coeff_replace', 8, 4)->nullable();

            // Порядок применения тоже замораживается: от него зависит результат.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('stone_reception_item_id');
            $table->index('workshop_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_item_modifiers');
    }
};
