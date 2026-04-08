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
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('offer_name');
            $table->text('description')->nullable();
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('discount_value', 10, 2);
            $table->enum('applies_to', ['all_services', 'specific_categories', 'specific_services'])->default('all_services');
            $table->json('categories')->nullable()->comment('Array of category names when applies_to is specific_categories');
            $table->json('services')->nullable()->comment('Array of service IDs when applies_to is specific_services');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->boolean('auto_apply')->default(false)->comment('Automatically apply at billing when matching service is selected');
            $table->enum('status', ['1', '0'])->default('1')->comment('1=Active, 0=Inactive');
            $table->integer('usage_count')->default(0)->comment('Number of times this discount has been used');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
