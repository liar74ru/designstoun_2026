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
        Schema::create('supplier_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('moysklad_id')->nullable()->unique();
            $table->string('number');
            $table->uuid('store_id');
            $table->uuid('counterparty_id');
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->string('status')->default('new'); // new | sent | error
            $table->text('note')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores');
            $table->foreign('counterparty_id')->references('id')->on('counterparties');
            $table->foreign('receiver_id')->references('id')->on('workers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_orders');
    }
};
