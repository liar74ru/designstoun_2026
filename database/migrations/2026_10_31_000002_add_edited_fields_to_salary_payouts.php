<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Отметка об исправлении последней выдачи: когда и кто. Прежние суммы не хранятся.
        Schema::table('salary_payouts', function (Blueprint $table) {
            $table->dateTime('edited_at')->nullable();
            $table->foreignId('edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('salary_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by_user_id');
            $table->dropColumn('edited_at');
        });
    }
};
