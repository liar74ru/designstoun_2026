<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Снимок начисленного по отделам на момент выдачи: от него сводка выплат делит
        // наличные / карту / налог / копилку между отделами. Пересчёт задним числом снимок
        // не меняет — как и accrued самой выдачи.
        Schema::create('salary_payout_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_payout_id')->constrained('salary_payouts')->cascadeOnDelete();
            // null — документы без отдела (и часы работника без основного отдела)
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->decimal('accrued', 12, 2);
            $table->timestamps();

            $table->unique(['salary_payout_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payout_departments');
    }
};
