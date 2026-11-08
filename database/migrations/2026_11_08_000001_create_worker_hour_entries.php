<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Часы ремонтных работ работника: не покрываются сдельной оплатой за продукцию,
        // оплачиваются по ставке отдела за час (HOURLY_RATE) и входят в начисление недели.
        Schema::create('worker_hour_entries', function (Blueprint $table) {
            $table->id();
            // Как у выдач и копилки: работника с историей удалить нельзя (WorkerService::HISTORY_REFERENCES)
            $table->foreignId('worker_id')->constrained('workers')->restrictOnDelete();
            // Основной отдел работника на момент записи — от него ставка
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 5, 2);
            // Ставка на момент записи: её правка задним числом не меняет начисленное
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->string('comment', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['worker_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_hour_entries');
    }
};
