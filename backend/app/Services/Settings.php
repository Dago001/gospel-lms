<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Administrator-editable, non-secret settings. Secrets (API keys) stay in the environment, never in the database or UI. */
class Settings
{
    /** key => [validation rule, description] */
    public const ALLOWED = [
        'academy.name' => ['string|max:120', 'Academy name shown on receipts and certificates'],
        'academy.legal_name' => ['nullable|string|max:200', 'Registered legal name'],
        'academy.address' => ['nullable|string|max:300', 'Postal address'],
        'academy.support_email' => ['nullable|email|max:190', 'Public support email'],
        'academy.signatory_name' => ['nullable|string|max:160', 'Certificate signatory'],
        'academy.signatory_title' => ['nullable|string|max:160', 'Certificate signatory title'],
        'site.maintenance_message' => ['nullable|string|max:300', 'Banner shown to all users'],
        'policy.refund_window_days' => ['integer|between:0,365', 'Refund window shown in the refund policy'],
    ];

    public static function all(): array
    {
        return Cache::remember('settings.all', 300, function () {
            return Schema::hasTable('system_settings') ? SystemSetting::all()->pluck('value', 'key')->all() : [];
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
    }

    public static function put(string $key, mixed $value, ?string $userId): void
    {
        SystemSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        Cache::forget('settings.all');
    }

    /** Academy identity: database overrides win over environment defaults. */
    public static function academy(): array
    {
        $base = config('academy');
        foreach (array_keys($base) as $k) {
            $base[$k] = self::get("academy.$k", $base[$k]);
        }

        return $base;
    }
}
