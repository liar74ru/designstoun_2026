<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавляем в таблицу users:
     *   - phone: используется как логин для работников
     *   - worker_id: связывает учётную запись с конкретным работником
     *   - is_admin: флаг администратора (видит всё, управляет системой)
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->unique()->after('email');
            $table->foreignId('worker_id')->nullable()->constrained('workers')->nullOnDelete()->after('phone');
            $table->boolean('is_admin')->default(false)->after('worker_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['worker_id']);
            $table->dropColumn(['phone', 'worker_id', 'is_admin']);
        });
    }
};
