<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Выдача зарплаты за невыданный период: снимок начисленного и раскладка выданного.
        Schema::create('salary_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->dateTime('period_from');
            $table->dateTime('period_to');
            $table->decimal('accrued', 12, 2);
            $table->decimal('requested_amount', 12, 2)->nullable();
            $table->decimal('paid_cash', 12, 2)->default(0);
            $table->decimal('paid_card', 12, 2)->nullable();
            $table->decimal('paid_tax', 12, 2)->nullable();
            $table->decimal('paid_total', 12, 2);
            // Перерасчёт прошлых выдач (коэффициенты поправили после «Выдано»), целиком в копилку.
            $table->decimal('recalc', 12, 2)->default(0);
            // Сколько по периоду этой выдачи уже учтено: accrued + перенесённые потом перерасчёты.
            $table->decimal('accounted', 12, 2);
            // accrued + recalc − paid_total: + ушло в копилку, − взято из копилки
            $table->decimal('to_bank', 12, 2);
            $table->dateTime('paid_at');
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comment', 255)->nullable();
            $table->timestamps();

            $table->index(['worker_id', 'period_to']);
        });

        // Журнал копилки: остаток = SUM(amount).
        Schema::create('piggy_bank_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('type', 20); // payout | adjustment
            $table->foreignId('salary_payout_id')->nullable()->constrained('salary_payouts')->cascadeOnDelete();
            $table->string('comment', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('worker_id');
        });

        // Запрос «к выдаче» на открытый период; null — по умолчанию (начислено − 10%).
        Schema::table('workers', function (Blueprint $table) {
            $table->decimal('requested_payout', 12, 2)->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn('requested_payout');
        });
        Schema::dropIfExists('piggy_bank_entries');
        Schema::dropIfExists('salary_payouts');
    }
};
