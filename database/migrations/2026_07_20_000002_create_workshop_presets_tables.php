<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пресеты (шаблоны) операций цеха per-department: набор строк сырья/тары/продукта
 * с нормой расхода на 1 единицу готовой продукции. Управляются админом на странице
 * отдела, применяются в форме создания операции цеха (норма × количество).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['department_id', 'name'], 'workshop_preset_dept_name_unique');
        });

        Schema::create('workshop_preset_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_preset_id')->constrained('workshop_presets')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('role')->index(); // raw | package | product (= WorkshopItem::ROLE_*)
            $table->decimal('quantity', 10, 3); // норма на 1 ед. готовой продукции
            $table->timestamps();

            $table->unique(['workshop_preset_id', 'product_id', 'role'], 'workshop_preset_item_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_preset_items');
        Schema::dropIfExists('workshop_presets');
    }
};
