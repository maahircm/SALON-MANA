<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('type', 20); // cash, card, bank, mobile, other
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Reference data the POS cannot work without; administrators may add or deactivate methods.
        $now = now();
        foreach ([['cash', 'Cash', 'cash'], ['card', 'Card', 'card'], ['bank_transfer', 'Bank transfer', 'bank'], ['mobile_money', 'Mobile money', 'mobile']] as $i => [$code, $name, $type]) {
            DB::table('payment_methods')->insert(['code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::create('cash_drawers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::table('cash_drawers')->insert(['name' => 'Main drawer', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        // Sequential, gap-free document numbers (invoices, refunds). Rows are locked while incrementing.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('cash_drawer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_drawer_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('opened_at');
            $table->decimal('opening_cash', 12, 2);
            $table->string('status', 10)->default('open'); // open, closed
            // Portable "one open session per drawer / per user": NULL once closed, unique while open.
            $table->unsignedBigInteger('open_drawer_key')->nullable()->unique();
            $table->unsignedBigInteger('open_user_key')->nullable()->unique();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->text('closing_notes')->nullable();
            $table->json('summary')->nullable(); // immutable snapshot taken at close
            $table->timestamps();
            $table->index(['opened_by', 'status']);
            $table->index('opened_at');
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_drawer_session_id')->constrained()->restrictOnDelete();
            $table->string('type', 10); // paid_in, paid_out
            $table->decimal('amount', 12, 2);
            $table->string('reason', 255);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['cash_movements', 'cash_drawer_sessions', 'document_sequences', 'cash_drawers', 'payment_methods'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
