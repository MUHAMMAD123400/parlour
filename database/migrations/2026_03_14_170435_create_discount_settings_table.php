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
        Schema::create('discount_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('staff_discount_limit')->default(10)->comment('Maximum discount % that counter staff can apply (0-50%)');
            $table->boolean('require_discount_reason')->default(true)->comment('If ON, staff must enter a reason when applying discount');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_settings');
    }
};
