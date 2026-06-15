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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('status')->default(1);
            $table->enum('staff_limit', [
                '5',
                '10',
                '25',
                '50',
                '100',
                'unlimited'
            ]);
            $table->enum('customer_limit', [
                '500',
                '1000',
                '2000',
                '5000',
                '10000',
                'unlimited'
            ]);
            $table->decimal('monthly', 10, 2);
            $table->decimal('quarterly', 10, 2);
            $table->decimal('yearly', 10, 2);
            $table->text('description')->nullable();
            $table->text('features')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
