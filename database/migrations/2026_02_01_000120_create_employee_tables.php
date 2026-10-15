<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 20)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name', 150)->index();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('job_title', 100)->nullable();
            $table->date('hire_date')->nullable();
            $table->string('status', 20)->default('active')->index(); // active, on_leave, suspended, terminated
            $table->date('termination_date')->nullable();
            $table->boolean('is_bookable')->default(true);
            $table->string('calendar_color', 7)->default('#8b3a62');
            $table->string('commission_type', 20)->default('none'); // none, percentage, fixed (rules finalised with commissions)
            $table->decimal('commission_rate', 8, 2)->default(0);
            $table->decimal('salary_amount', 12, 2)->nullable(); // sensitive: employees.sensitive
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('employee_skill', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['employee_id', 'skill_id']);
        });

        // Recurring weekly working hours (weekday: 0 = Sunday ... 6 = Saturday, Carbon dayOfWeek).
        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->index(['employee_id', 'weekday']);
        });

        // Dated shift overrides: type "work" replaces the weekly hours for that date, "off" blocks the date.
        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('shift_date');
            $table->string('type', 10)->default('work');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'shift_date']);
        });
    }

    public function down(): void
    {
        foreach (['employee_shifts', 'employee_schedules', 'employee_skill', 'employees'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
