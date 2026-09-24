<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Зеркало статусов customerorder из МойСклад. PK — id статуса в МойСклад,
        // как у stores: он стабилен, и orders.state_moysklad_id ссылается именно на него.
        Schema::create('order_states', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->unsignedInteger('color')->nullable()->comment('Цвет из МойСклад, RGB числом');
            $t->string('state_type')->nullable()->comment('Regular / Successful / Unsuccessful');
            $t->unsignedInteger('position')->default(0)->comment('Порядок в метаданных МойСклад');
            $t->boolean('is_enabled')->default(false)->comment('Используется в программе');
            $t->boolean('archived')->default(false)->comment('Статуса больше нет в МойСклад');
            $t->timestamps();

            $t->index(['is_enabled', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_states');
    }
};
