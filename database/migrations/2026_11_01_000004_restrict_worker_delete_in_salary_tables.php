<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['salary_payouts', 'piggy_bank_entries'];

    // Удаление работника стирало выдачи и копилку каскадом: теперь БД такого не допустит.
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['worker_id']);
                $table->foreign('worker_id')->references('id')->on('workers')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['worker_id']);
                $table->foreign('worker_id')->references('id')->on('workers')->cascadeOnDelete();
            });
        }
    }
};
