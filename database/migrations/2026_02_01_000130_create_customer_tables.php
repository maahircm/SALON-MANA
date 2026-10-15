<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_number', 20)->nullable()->unique();
            $table->string('name', 150)->index();
            $table->string('phone', 30)->nullable();
            $table->string('phone_normalized', 30)->nullable()->index(); // digits only, for duplicate detection
            $table->string('email')->nullable()->index();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('preferred_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('active')->index(); // active, inactive, blocked
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();
            $table->date('registered_on');
            $table->timestamp('anonymized_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes(); // soft delete == archive; history is preserved
        });

        Schema::create('customer_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // preference, allergy, medical_note, service_history
            $table->text('value');
            $table->boolean('is_sensitive')->default(false); // visible only with customers.sensitive
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_preferences');
        Schema::dropIfExists('customers');
    }
};
