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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_name');
            $table->string('brand')->nullable();
            $table->string('category');
            $table->text('description')->nullable();
            $table->integer('quantity_in_stock');
            $table->string('unit')->nullable();
            $table->decimal('purchase_price', 10, 2);
            $table->decimal('selling_price', 10, 2)->nullable();
            $table->integer('minimum_stock_alert')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
