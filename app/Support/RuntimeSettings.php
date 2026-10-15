<?php

namespace App\Support;

use App\Services\SettingsService;

/**
 * Applies the salon profile stored in the database (name, currency, formats, timezone) to the running configuration,
 * so every page, job and command uses what an administrator saved under Settings > General.
 */
final class RuntimeSettings
{
    public const DATE_FORMATS = ['Y-m-d' => '2026-10-19', 'd/m/Y' => '19/10/2026', 'm/d/Y' => '10/19/2026', 'd-m-Y' => '19-10-2026', 'd M Y' => '19 Oct 2026'];

    public const TIME_FORMATS = ['H:i' => '14:30 (24-hour)', 'h:i A' => '02:30 PM (12-hour)'];

    public static function apply(SettingsService $settings): void
    {
        try {
            $settings->flush();
            $map = ['general.salon_name' => 'salon.name', 'general.currency' => 'salon.currency', 'general.currency_symbol' => 'salon.currency_symbol',
                'general.date_format' => 'salon.date_format', 'general.time_format' => 'salon.time_format'];
            foreach ($map as $setting => $config) {
                $value = $settings->get($setting);
                if (is_string($value) && $value !== '') {
                    config([$config => $value]);
                }
            }
            $timezone = $settings->get('general.timezone');
            if (is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
                config(['app.timezone' => $timezone]);
                date_default_timezone_set($timezone);
            }
        } catch (\Throwable) {
            // Database not migrated yet (fresh install, first migrate): keep the .env defaults.
        }
    }
}
