<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_operation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('operation_key', 64);
            $table->boolean('enabled')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'operation_key']);
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_operation_settings');
    }
};
