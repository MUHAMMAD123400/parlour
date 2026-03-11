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
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('title')->nullable()->after('name');
            $table->text('description')->nullable()->after('title');
            $table->string('type')->nullable()->after('description');
            $table->string('module')->nullable()->after('type');
            $table->string('group_type')->nullable()->after('module');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'description',
                'type',
                'module',
                'group_type'
            ]);
        });
    }
};