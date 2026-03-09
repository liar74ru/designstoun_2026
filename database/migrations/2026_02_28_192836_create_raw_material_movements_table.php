<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_material_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('raw_material_batches')->onDelete('cascade');
            $table->uuid('from_store_id')->nullable();
            $table->foreign('from_store_id')->references('id')->on('stores')->onDelete('set null');
            $table->uuid('to_store_id')->nullable();
            $table->foreign('to_store_id')->references('id')->on('stores')->onDelete('set null');
            $table->foreignId('from_worker_id')->nullable()->constrained('workers')->onDelete('set null');
            $table->foreignId('to_worker_id')->nullable()->constrained('workers')->onDelete('set null');
            $table->foreignId('moved_by')->nullable()->constrained('workers')->onDelete('set null');
            $table->enum('movement_type', ['create', 'transfer_to_worker', 'return_to_store', 'use']);
            $table->decimal('quantity', 10, 3);
            $table->timestamps();

            $table->index('batch_id');
            $table->index('movement_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_material_movements');
    }
};
