<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per commissioned invoice line. Rule and rate are snapshotted, so later rate changes never rewrite history.
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_item_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('basis_amount', 12, 2); // line value after discounts, before tax
            $table->string('rule_type', 12); // percentage, fixed
            $table->decimal('rate', 8, 2); // percent, or fixed amount per unit
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 12, 2);
            $table->timestamp('accrued_at')->useCurrent()->index();
            $table->index(['employee_id', 'accrued_at']);
        });

        // Signed corrections: refunds, voids and manual bonuses / deductions. Append-only.
        Schema::create('commission_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('commission_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2); // negative reduces what the employee earned
            $table->string('type', 10); // refund, void, manual
            $table->string('reason')->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_adjustments');
        Schema::dropIfExists('commissions');
    }
};
