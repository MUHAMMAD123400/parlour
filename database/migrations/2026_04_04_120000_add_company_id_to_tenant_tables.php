<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $companyId = DB::table('companies')->orderBy('id')->value('id');

        $tenantTables = [
            'categories',
            'customers',
            'products',
            'services',
            'discounts',
            'discount_settings',
            'bills',
        ];

        foreach ($tenantTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            if (DB::table($tableName)->exists() && ! $companyId) {
                throw new RuntimeException(
                    'Cannot add company_id: tenant tables have rows but no company exists. Create a company first, then re-run migrations.'
                );
            }
        }

        foreach ($tenantTables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('id');
            });
        }

        if ($companyId) {
            foreach ($tenantTables as $tableName) {
                if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                    continue;
                }
                DB::table($tableName)->whereNull('company_id')->update(['company_id' => $companyId]);
            }
        }

        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'company_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropUnique(['category_name']);
            });
        }

        if (Schema::hasTable('bills') && Schema::hasColumn('bills', 'company_id')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->dropUnique(['bill_number']);
            });
        }

        foreach ($tenantTables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->foreign('company_id')
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();

                if ($tableName === 'categories') {
                    $table->unique(['company_id', 'category_name']);
                }
                if ($tableName === 'bills') {
                    $table->unique(['company_id', 'bill_number']);
                }
                if ($tableName === 'discount_settings') {
                    $table->unique(['company_id']);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tenantTables = [
            'bills',
            'discount_settings',
            'discounts',
            'services',
            'products',
            'customers',
            'categories',
        ];

        foreach ($tenantTables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if ($tableName === 'categories') {
                    $table->dropUnique(['company_id', 'category_name']);
                }
                if ($tableName === 'bills') {
                    $table->dropUnique(['company_id', 'bill_number']);
                }
                if ($tableName === 'discount_settings') {
                    $table->dropUnique(['company_id']);
                }

                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
            });
        }

        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unique('category_name');
            });
        }

        if (Schema::hasTable('bills')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->unique('bill_number');
            });
        }
    }
};
