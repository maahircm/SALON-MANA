<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customer segments and the new-vs-returning report filter on the registration date.
        Schema::table('customers', fn (Blueprint $table) => $table->index('registered_on'));
        // Customer lifetime value / returning-customer queries group issued invoices per customer over time.
        Schema::table('invoices', fn (Blueprint $table) => $table->index(['customer_id', 'issued_at']));
        // The delivery log is filtered and sorted by creation date.
        Schema::table('notification_logs', fn (Blueprint $table) => $table->index('created_at'));
    }

    public function down(): void
    {
        Schema::table('notification_logs', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropIndex(['customer_id', 'issued_at']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropIndex(['registered_on']));
    }
};
