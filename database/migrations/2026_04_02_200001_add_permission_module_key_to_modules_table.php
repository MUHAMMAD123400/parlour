<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->string('permission_module_key', 64)
                ->nullable()
                ->after('module_name')
                ->comment('Matches permissions.module (e.g. billing, customer)');
            $table->unique('permission_module_key');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropUnique(['permission_module_key']);
            $table->dropColumn('permission_module_key');
        });
    }
};
