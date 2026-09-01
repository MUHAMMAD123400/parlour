<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes to all tenant-scoped tables.
     *
     * Index strategy for 1 M-user scale:
     *  - Every table that carries company_id gets a dedicated index on that
     *    column so the query planner can satisfy tenant-scoped lookups in O(log n).
     *  - High-frequency join/filter columns get composite indexes.
     *  - created_at is indexed where time-range reports are common.
     *  - All additions are guarded with hasIndex() checks so the migration is
     *    safe to re-run after a partial failure.
     */
    public function up(): void
    {
        // ── customers ────────────────────────────────────────────────────────
        Schema::table('customers', function (Blueprint $table) {
            if (!$this->hasIndex('customers', 'customers_company_id_index')) {
                $table->index('company_id', 'customers_company_id_index');
            }
            // Common search: company + phone lookup
            if (!$this->hasIndex('customers', 'customers_company_phone_index')) {
                $table->index(['company_id', 'phone'], 'customers_company_phone_index');
            }
            // Reports filter by creation date per company
            if (!$this->hasIndex('customers', 'customers_company_created_index')) {
                $table->index(['company_id', 'created_at'], 'customers_company_created_index');
            }
        });

        // ── bills ─────────────────────────────────────────────────────────────
        Schema::table('bills', function (Blueprint $table) {
            if (!$this->hasIndex('bills', 'bills_company_id_index')) {
                $table->index('company_id', 'bills_company_id_index');
            }
            // Revenue reports: filter by company + date
            if (!$this->hasIndex('bills', 'bills_company_created_index')) {
                $table->index(['company_id', 'created_at'], 'bills_company_created_index');
            }
            // Customer billing history: company + customer
            if (!$this->hasIndex('bills', 'bills_company_customer_index')) {
                $table->index(['company_id', 'customer_id'], 'bills_company_customer_index');
            }
            // Staff report: company + creator
            if (!$this->hasIndex('bills', 'bills_company_user_index')) {
                $table->index(['company_id', 'user_id'], 'bills_company_user_index');
            }
            // Payment method filter
            if (!$this->hasIndex('bills', 'bills_company_payment_method_index')) {
                $table->index(['company_id', 'payment_method'], 'bills_company_payment_method_index');
            }
        });

        // ── bill_items ────────────────────────────────────────────────────────
        // company_bill composite already created in the previous migration;
        // add service-level lookup for service usage reports.
        Schema::table('bill_items', function (Blueprint $table) {
            if (!$this->hasIndex('bill_items', 'bill_items_company_service_index')) {
                $table->index(['company_id', 'service_id'], 'bill_items_company_service_index');
            }
            // Item type filter (service vs product)
            if (!$this->hasIndex('bill_items', 'bill_items_company_type_index')) {
                $table->index(['company_id', 'item_type'], 'bill_items_company_type_index');
            }
        });

        // ── services ─────────────────────────────────────────────────────────
        Schema::table('services', function (Blueprint $table) {
            if (!$this->hasIndex('services', 'services_company_id_index')) {
                $table->index('company_id', 'services_company_id_index');
            }
            // Category-scoped service list (common dropdown query)
            if (!$this->hasIndex('services', 'services_company_category_index')) {
                $table->index(['company_id', 'category_id'], 'services_company_category_index');
            }
            // Active services filter
            if (!$this->hasIndex('services', 'services_company_status_index')) {
                $table->index(['company_id', 'status'], 'services_company_status_index');
            }
        });

        // ── products ──────────────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            if (!$this->hasIndex('products', 'products_company_id_index')) {
                $table->index('company_id', 'products_company_id_index');
            }
            // Category-scoped product list
            if (!$this->hasIndex('products', 'products_company_category_index')) {
                $table->index(['company_id', 'category_id'], 'products_company_category_index');
            }
            // Low-stock alert queries: company + stock level
            if (!$this->hasIndex('products', 'products_company_stock_index')) {
                $table->index(['company_id', 'quantity_in_stock'], 'products_company_stock_index');
            }
        });

        // ── categories ────────────────────────────────────────────────────────
        Schema::table('categories', function (Blueprint $table) {
            if (!$this->hasIndex('categories', 'categories_company_id_index')) {
                $table->index('company_id', 'categories_company_id_index');
            }
            // Active categories filter (dropdown)
            if (!$this->hasIndex('categories', 'categories_company_status_index')) {
                $table->index(['company_id', 'status'], 'categories_company_status_index');
            }
        });

        // ── discounts ─────────────────────────────────────────────────────────
        Schema::table('discounts', function (Blueprint $table) {
            if (!$this->hasIndex('discounts', 'discounts_company_id_index')) {
                $table->index('company_id', 'discounts_company_id_index');
            }
            // Auto-apply billing lookup: company + active + date range
            if (!$this->hasIndex('discounts', 'discounts_company_status_index')) {
                $table->index(['company_id', 'status'], 'discounts_company_status_index');
            }
            // Date-validity filter for auto-apply
            if (!$this->hasIndex('discounts', 'discounts_company_validity_index')) {
                $table->index(['company_id', 'valid_from', 'valid_to'], 'discounts_company_validity_index');
            }
        });

        // ── discount_settings ─────────────────────────────────────────────────
        // Already has UNIQUE(company_id) which doubles as an index — no extra needed.

        // ── company_modules ───────────────────────────────────────────────────
        Schema::table('company_modules', function (Blueprint $table) {
            // Fast module-key lookup per company (used by EnsureCompanyHasModule middleware)
            if (!$this->hasIndex('company_modules', 'company_modules_company_status_index')) {
                $table->index(['company_id', 'company_module_status'], 'company_modules_company_status_index');
            }
        });

        // ── personal_access_tokens ────────────────────────────────────────────
        // Sanctum already indexes tokenable_id; add expiry for cleanup queries.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (!$this->hasIndex('personal_access_tokens', 'pat_expires_at_index')) {
                $table->index('expires_at', 'pat_expires_at_index');
            }
        });
    }

    /**
     * Drop all indexes added above.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_company_id_index');
            $table->dropIndex('customers_company_phone_index');
            $table->dropIndex('customers_company_created_index');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex('bills_company_id_index');
            $table->dropIndex('bills_company_created_index');
            $table->dropIndex('bills_company_customer_index');
            $table->dropIndex('bills_company_user_index');
            $table->dropIndex('bills_company_payment_method_index');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropIndex('bill_items_company_service_index');
            $table->dropIndex('bill_items_company_type_index');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex('services_company_id_index');
            $table->dropIndex('services_company_category_index');
            $table->dropIndex('services_company_status_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_company_id_index');
            $table->dropIndex('products_company_category_index');
            $table->dropIndex('products_company_stock_index');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_company_id_index');
            $table->dropIndex('categories_company_status_index');
        });

        Schema::table('discounts', function (Blueprint $table) {
            $table->dropIndex('discounts_company_id_index');
            $table->dropIndex('discounts_company_status_index');
            $table->dropIndex('discounts_company_validity_index');
        });

        Schema::table('company_modules', function (Blueprint $table) {
            $table->dropIndex('company_modules_company_status_index');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('pat_expires_at_index');
        });
    }

    /**
     * Helper: check if a named index already exists on a table.
     * Prevents "Duplicate key name" errors on re-runs.
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = \Illuminate\Support\Facades\DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        );

        return count($indexes) > 0;
    }
};
