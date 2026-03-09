<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_material_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->decimal('initial_quantity', 10, 3);
            $table->decimal('remaining_quantity', 10, 3);
            $table->enum('status', ['active', 'used', 'returned', 'archived'])->default('active');
            $table->uuid('current_store_id');
            $table->foreign('current_store_id')->references('id')->on('stores')->onDelete('restrict');
            $table->foreignId('current_worker_id')->nullable()->constrained('workers')->onDelete('set null');
            $table->string('batch_number')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['current_worker_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_material_batches');
    }
};
