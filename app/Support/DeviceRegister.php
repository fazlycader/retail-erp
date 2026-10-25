<?php

namespace App\Support;

use App\Models\Register;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Which register (cash point) this device is: an encrypted pos_register cookie, chosen once.
 * Only a register at the given location counts; when the location has a single active register
 * it is used automatically.
 */
final class DeviceRegister
{
    public const COOKIE = 'pos_register';

    public static function id(?Request $request, ?int $locationId): ?int
    {
        if (! $locationId) {
            return null;
        }

        $id = (int) $request?->cookie(self::COOKIE);

        if ($id && Register::query()->where('location_id', $locationId)->where('is_active', true)->whereKey($id)->exists()) {
            return $id;
        }

        $registers = Register::query()->where('location_id', $locationId)->where('is_active', true)->limit(2)->pluck('id');

        return $registers->count() === 1 ? $registers->first() : null;
    }

    /** The register of the device making the current request, at the current location. */
    public static function current(?int $locationId = null): ?int
    {
        return self::id(app()->bound('request') ? request() : null, $locationId ?? CurrentLocation::id());
    }

    public static function remember(Register $register): void
    {
        Cookie::queue(self::COOKIE, (string) $register->id, 60 * 24 * 365);
    }
}
