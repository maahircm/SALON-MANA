<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free sequential document numbers. Must be called inside the transaction that uses the number,
 * so a rolled-back sale never burns a number.
 */
class DocumentNumberService
{
    public function __construct(private SettingsService $settings) {}

    public function next(string $key): string
    {
        DB::table('document_sequences')->insertOrIgnore(['key' => $key, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('document_sequences')->where('key', $key)->lockForUpdate()->first();
        $number = $row->last_number + 1;
        DB::table('document_sequences')->where('key', $key)->update(['last_number' => $number, 'updated_at' => now()]);

        $prefix = (string) $this->settings->get("pos.{$key}_prefix", strtoupper(substr($key, 0, 3)).'-');

        return $prefix.str_pad((string) $number, (int) $this->settings->get('pos.number_padding', 6), '0', STR_PAD_LEFT);
    }
}
