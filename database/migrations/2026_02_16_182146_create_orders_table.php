<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('moysklad_id')->unique()->comment('ID в МойСклад');
            $table->string('name')->comment('Номер заказа');
            $table->text('description')->nullable()->comment('Описание/комментарий');
            $table->decimal('sum', 12, 2)->default(0)->comment('Сумма заказа');
            $table->decimal('shipped_sum', 12, 2)->default(0)->comment('Отгружено на сумму');
            $table->decimal('payed_sum', 12, 2)->default(0)->comment('Оплачено');
            $table->string('state')->nullable()->comment('Статус');
            $table->string('state_name')->nullable()->comment('Название статуса');
            $table->string('agent_id')->nullable()->comment('ID контрагента');
            $table->string('agent_name')->nullable()->comment('Название контрагента');
            $table->string('organization_id')->nullable()->comment('ID юрлица');
            $table->string('organization_name')->nullable()->comment('Название юрлица');
            $table->timestamp('moment')->nullable()->comment('Дата заказа');
            $table->timestamp('delivery_planned_at')->nullable()->comment('Плановая дата отгрузки');
            $table->json('positions')->nullable()->comment('Позиции заказа');
            $table->json('attributes')->nullable()->comment('Дополнительные реквизиты');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Индексы для быстрого поиска
            $table->index('moysklad_id');
            $table->index('name');
            $table->index('moment');
            $table->index('state');
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
