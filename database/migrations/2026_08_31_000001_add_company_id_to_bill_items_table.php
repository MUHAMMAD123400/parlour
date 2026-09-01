<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add company_id to bill_items so that:
     *  - Each row is explicitly scoped to a tenant company.
     *  - The BelongsToCompany / CompanyScope global scope works on BillItem queries.
     *  - A bare query on bill_items no longer requires a JOIN through bills.
     *
     * Strategy for existing rows:
     *  Derive company_id from the parent bill so no data is lost.
     */
    public function up(): void
    {
        // 1. Add the nullable column first (avoids issues with existing rows).
        Schema::table('bill_items', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')
                ->nullable()
                ->after('id')
                ->comment('Tenant scope — mirrors parent bill.company_id');
        });

        // 2. Back-fill from the parent bills table.
        DB::statement('
            UPDATE bill_items bi
            INNER JOIN bills b ON b.id = bi.bill_id
            SET bi.company_id = b.company_id
            WHERE bi.company_id IS NULL
        ');

        // 3. Make non-nullable now that every row has a value.
        Schema::table('bill_items', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')
                ->nullable(false)
                ->change();
        });

        // 4. Add the foreign key constraint with cascade delete.
        //    (If the parent company is deleted, its bills cascade, and this
        //    mirrors that behaviour at the item level as well.)
        Schema::table('bill_items', function (Blueprint $table) {
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
        });

        // 5. Composite index for the most common tenant query:
        //    "all items for company X, newest first"
        Schema::table('bill_items', function (Blueprint $table) {
            $table->index(['company_id', 'bill_id'], 'bill_items_company_bill_index');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropIndex('bill_items_company_bill_index');
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
