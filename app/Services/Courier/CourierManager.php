<?php

namespace App\Services\Courier;

use App\Models\CourierSetting;

class CourierManager
{
    /** @return array<string, CourierService> active services for current tenant */
    public static function activeServices(): array
    {
        $services = [];

        foreach (CourierSetting::where('is_active', 1)->get() as $setting) {
            $service = self::make($setting);
            if ($service) {
                $services[$setting->provider] = $service;
            }
        }

        return $services;
    }

    public static function forProvider(string $provider): ?CourierService
    {
        $setting = CourierSetting::where('provider', $provider)->where('is_active', 1)->first();

        return $setting ? self::make($setting) : null;
    }

    protected static function make(CourierSetting $setting): ?CourierService
    {
        $c = $setting->credentials ?? [];

        return match ($setting->provider) {
            'steadfast' => isset($c['api_key'], $c['secret_key'])
                ? new SteadfastService($c['api_key'], $c['secret_key']) : null,
            'pathao' => isset($c['client_id'], $c['client_secret'], $c['username'], $c['password'], $c['store_id'])
                ? new PathaoService($c['client_id'], $c['client_secret'], $c['username'], $c['password'], $c['store_id']) : null,
            default => null,
        };
    }
}
