<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Arr;

/** Key/value salon settings backed by salon_settings, falling back to config/salon.php defaults. */
class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->cache ??= Setting::query()->pluck('value', 'key')->all();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return Arr::get(config('salon'), $key, $default);
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        $this->cache = null;
    }

    public function flush(): void
    {
        $this->cache = null;
    }
}
