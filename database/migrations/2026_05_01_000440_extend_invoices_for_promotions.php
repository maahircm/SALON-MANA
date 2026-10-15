<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('membership_plan_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
            $table->string('discount_source', 12)->nullable()->after('discount_type'); // manual, membership
            $table->decimal('coupon_discount', 12, 2)->default(0)->after('discount_amount');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('appointment_id')->constrained()->restrictOnDelete();
            $table->decimal('coupon_discount_total', 12, 2)->default(0)->after('discount_total');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('coupon_discount_total');
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('membership_plan_id');
            $table->dropColumn(['discount_source', 'coupon_discount']);
        });
    }
};
