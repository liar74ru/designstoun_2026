<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Статусы, заявки в которых список показывает в самом конце (например, «Собран»):
        // работы по ним нет, а место в очереди они занимают. Влияет только на порядок списка,
        // не на очередь раздачи остатка между заявками.
        Schema::table('order_states', function (Blueprint $table) {
            $table->boolean('is_list_bottom')->default(false)->after('is_default_filter');
        });
    }

    public function down(): void
    {
        Schema::table('order_states', function (Blueprint $table) {
            $table->dropColumn('is_list_bottom');
        });
    }
};
