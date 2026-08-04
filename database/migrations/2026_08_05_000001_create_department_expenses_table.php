<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Произвольный список накладных расходов отдела (₽/м²).
 * Состав и количество строк у каждого отдела свои, наследования нет.
 * Сумма строк = накладные, входящие в processingSum техоперации МойСклад.
 *
 * Порядок строк — по id (сохранение сделано как replace-all, поэтому
 * порядок id совпадает с порядком строк формы); отдельного sort_order нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamps();

            // Имя — подпись строки, а не идентификатор: unique(department_id, name)
            // намеренно не ставим, дубликаты имён безобидны.
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_expenses');
    }
};
