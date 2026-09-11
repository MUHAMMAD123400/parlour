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
        Schema::create('company_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 50)->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('plan_id')->constrained('plans')->onDelete('cascade');
            $table->string('billing_cycle', 20)->default('monthly'); // monthly, quarterly, yearly
            $table->string('payment_status', 20)->default('pending'); // pending, paid, overdue, cancelled
            $table->date('date');
            $table->decimal('amount', 12, 2)->default(0.00);
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('total_amount', 12, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Performance Indexes
            $table->index('company_id');
            $table->index('plan_id');
            $table->index('payment_status');
            $table->index('billing_cycle');
            $table->index('date');
            $table->index(['payment_status', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_invoices');
    }
};
