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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('company_type')->nullable()->after('company_description');
            $table->string('ntn')->nullable()->after('company_type');
            $table->string('strn')->nullable()->after('ntn');
            $table->string('license_number')->nullable()->after('strn');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['company_type', 'ntn', 'strn', 'license_number']);
        });
    }
};
