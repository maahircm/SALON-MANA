<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('balance')->default(0); // unsigned: a negative balance is impossible
            $table->unsignedInteger('lifetime_earned')->default(0);
            $table->timestamps();
        });

        // Append-only points ledger; balance = running total of points.
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_loyalty_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // earn, redeem, earn_reversal, redeem_refund, adjustment
            $table->integer('points'); // signed
            $table->unsignedInteger('balance_after');
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('refund_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('idempotency_key', 80)->nullable()->unique(); // an invoice can earn / redeem exactly once
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_id', 'id']);
        });

        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('duration_days');
            $table->decimal('discount_percent', 5, 2)->default(0); // automatic discount on services and products
            $table->decimal('points_multiplier', 4, 2)->default(1); // loyalty earn multiplier
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_plan_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('invoice_id')->nullable()->unique(); // the sale that created it (at most one membership per invoice)
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 10)->default('active'); // active, cancelled, expired
            // Plan terms are snapshotted at purchase.
            $table->decimal('price_paid', 12, 2);
            $table->decimal('discount_percent', 5, 2);
            $table->decimal('points_multiplier', 4, 2);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'status', 'ends_on']);
        });

        DB::table('payment_methods')->insertOrIgnore(['code' => 'loyalty_points', 'name' => 'Loyalty points', 'type' => 'loyalty', 'is_active' => true, 'sort_order' => 90, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('payment_methods')->where('code', 'loyalty_points')->delete();
        foreach (['customer_memberships', 'membership_plans', 'loyalty_transactions', 'customer_loyalty_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
