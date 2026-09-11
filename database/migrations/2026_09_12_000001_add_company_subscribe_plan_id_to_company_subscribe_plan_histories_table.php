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
        Schema::table('company_subscribe_plan_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('company_subscribe_plan_histories', 'company_subscribe_plan_id')) {
                $table->unsignedBigInteger('company_subscribe_plan_id')->nullable()->after('company_id');
            }
            $table->foreign('company_subscribe_plan_id', 'csph_company_sub_plan_id_foreign')
                ->references('id')
                ->on('company_subscribe_plans')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_subscribe_plan_histories', function (Blueprint $table) {
            $table->dropForeign('csph_company_sub_plan_id_foreign');
            $table->dropColumn('company_subscribe_plan_id');
        });
    }
};
