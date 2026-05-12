<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('orders');

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('moysklad_id')->unique();
            $t->string('name');
            $t->string('state_moysklad_id')->nullable();
            $t->string('state_name')->nullable();
            $t->foreignUuid('counterparty_id')->nullable()
                ->constrained('counterparties')->nullOnDelete();
            $t->string('agent_name')->nullable();
            $t->timestamp('moment')->nullable();
            $t->json('attributes')->nullable();
            $t->timestamps();
            $t->index(['state_name', 'moment']);
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $t->string('product_moysklad_id')->nullable();
            $t->string('product_name')->nullable();
            $t->decimal('quantity', 12, 3)->default(0);
            $t->decimal('shipped', 12, 3)->default(0);
            $t->string('uom_name')->nullable();
            $t->timestamps();
            $t->index('product_id');
        });

        Schema::create('order_department', function (Blueprint $t) {
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('department_id')->constrained()->cascadeOnDelete();
            $t->primary(['order_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_department');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
