<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pre-create the counters so numbering never has to insert a row while other sales hold locks.
     */
    public function up(): void
    {
        $now = now();
        foreach (['invoice', 'refund', 'purchase_order', 'goods_receipt', 'supplier_return'] as $key) {
            DB::table('document_sequences')->insertOrIgnore(['key' => $key, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        //
    }
};
