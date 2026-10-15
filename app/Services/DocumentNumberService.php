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

    /**
     * @param  string|null  $settingKey  settings key holding the prefix (defaults to pos.{key}_prefix)
     * @param  string|null  $defaultPrefix  prefix used when the setting is absent
     */
    public function next(string $key, ?string $settingKey = null, ?string $defaultPrefix = null): string
    {
        $row = DB::table('document_sequences')->where('key', $key)->lockForUpdate()->first();
        if ($row === null) {
            // Unknown counter (normally pre-created by a migration): create it, then lock it like any other.
            DB::table('document_sequences')->insertOrIgnore(['key' => $key, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('document_sequences')->where('key', $key)->lockForUpdate()->first();
        }
        $number = $row->last_number + 1;
        DB::table('document_sequences')->where('key', $key)->update(['last_number' => $number, 'updated_at' => now()]);

        $prefix = (string) $this->settings->get($settingKey ?? "pos.{$key}_prefix", $defaultPrefix ?? strtoupper(substr($key, 0, 3)).'-');

        return $prefix.str_pad((string) $number, (int) $this->settings->get('pos.number_padding', 6), '0', STR_PAD_LEFT);
    }
}
