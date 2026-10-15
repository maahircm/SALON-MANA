<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 30)->nullable()->unique(); // assigned when issued
            $table->string('status', 10)->default('draft')->index(); // draft, issued, voided
            $table->string('payment_status', 20)->default('unpaid')->index(); // unpaid, partially_paid, paid
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3);
            $table->decimal('subtotal', 12, 2)->default(0); // gross of line discounts, before tax
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0); // sum of payments (never reduced by refunds)
            $table->decimal('refunded_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('checkout_key', 64)->nullable()->unique(); // idempotency for the checkout submit
            $table->timestamp('issued_at')->nullable()->index();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['status', 'payment_status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20)->default('service'); // service (product lines arrive with inventory)
            $table->foreignId('service_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id')->nullable(); // FK added with the products table
            $table->foreignId('appointment_service_id')->nullable()->constrained('appointment_services')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 190);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->string('discount_type', 10)->default('none'); // none, percent, fixed
            $table->decimal('discount_value', 12, 2)->default(0); // as entered (percent or amount)
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2); // quantity * unit_price - discount + tax
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('appointment_service_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_drawer_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2); // amount applied to the invoice
            $table->decimal('tendered_amount', 12, 2)->nullable(); // cash handed over
            $table->decimal('change_given', 12, 2)->default(0);
            $table->string('reference', 100)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->useCurrent()->index();
            $table->timestamps();
            $table->index(['invoice_id', 'payment_method_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number', 30)->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_drawer_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->string('reference', 100)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('refunded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('refunded_at')->useCurrent()->index();
            $table->timestamps();
            $table->index(['invoice_id', 'payment_method_id']);
        });

        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 12, 2); // value of the refunded quantity, incl. its share of discount and tax
        });
    }

    public function down(): void
    {
        foreach (['refund_items', 'refunds', 'payments', 'invoice_items', 'invoices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
