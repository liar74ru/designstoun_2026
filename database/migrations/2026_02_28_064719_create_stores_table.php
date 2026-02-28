<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255); // Наименование Склада
            $table->string('code', 255)->nullable(); // Код Склада
            $table->string('external_code', 255)->nullable(); // Внешний код Склада
            $table->text('description')->nullable(); // Комментарий к Складу
            $table->string('address', 255)->nullable(); // Адрес склада
            $table->json('address_full')->nullable(); // Адрес с детализацией
            $table->boolean('archived')->default(false); // Архивирован ли
            $table->boolean('shared')->default(false); // Общий доступ
            $table->string('path_name')->nullable(); // Группа Склада
            $table->uuid('account_id')->nullable(); // ID учетной записи
            $table->uuid('owner_id')->nullable(); // Владелец (Сотрудник)
            $table->uuid('parent_id')->nullable(); // ID родительского склада
            $table->json('attributes')->nullable(); // Дополнительные поля
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
