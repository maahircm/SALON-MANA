<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number', 30)->unique();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('amount', 12, 2);
            $table->date('expense_date')->index();
            $table->string('status', 12)->default('pending')->index(); // pending, approved, rejected, cancelled
            $table->string('payment_status', 10)->default('unpaid'); // unpaid, paid
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name', 150)->nullable();
            $table->string('receipt_mime', 60)->nullable();
            $table->unsignedInteger('receipt_size')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'expense_date']);
        });

        // Append-only approval history.
        Schema::create('expense_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20); // submitted, edited, approved, rejected, cancelled, paid
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $now = now();
        DB::table('expense_categories')->insert(collect(['Rent', 'Salaries', 'Utilities', 'Supplies', 'Marketing', 'Maintenance', 'Transport', 'Other'])
            ->map(fn ($name) => ['name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());
        DB::table('document_sequences')->insertOrIgnore(['key' => 'expense', 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_events');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        DB::table('document_sequences')->where('key', 'expense')->delete();
    }
};
