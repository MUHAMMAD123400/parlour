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
        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('bills')->onDelete('cascade');
            $table->foreignId('service_id')->nullable()->constrained('services')->onDelete('set null');
            $table->string('item_name')->comment('Service or product name');
            $table->string('item_type')->default('service')->comment('service or product');
            $table->string('category')->nullable()->comment('Service/product category');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->comment('Price per unit at time of billing');
            $table->decimal('total_price', 10, 2)->comment('quantity * unit_price');
            $table->integer('duration')->nullable()->comment('Service duration in minutes');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_items');
    }
};
