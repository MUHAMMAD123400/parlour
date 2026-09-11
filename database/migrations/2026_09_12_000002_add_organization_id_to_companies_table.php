<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'organization_id')) {
                $table->uuid('organization_id')->nullable()->unique()->after('id');
            }
        });

        // Populate existing companies with UUID if any
        $companies = DB::table('companies')->whereNull('organization_id')->get();
        foreach ($companies as $company) {
            DB::table('companies')->where('id', $company->id)->update([
                'organization_id' => (string) Str::uuid(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'organization_id')) {
                $table->dropUnique(['organization_id']);
                $table->dropColumn('organization_id');
            }
        });
    }
};
