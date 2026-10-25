<?php

namespace App\Support;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * A device a manager has marked as a shared terminal (counter PC or tablet) for one location.
 * Staff switch on it with their PIN. The marker is an encrypted, long-lived cookie, so PIN login
 * only works on devices a manager set up — nobody on the internet can try PINs.
 */
final class Terminal
{
    public const COOKIE = 'shared_terminal';

    public const DAYS = 365;

    /**
     * @return array{id: string, location_id: int, enabled_by: int}|null
     */
    public static function fromRequest(Request $request): ?array
    {
        $data = json_decode((string) $request->cookie(self::COOKIE), true);

        if (! is_array($data) || ! isset($data['id'], $data['location_id']) || ! Location::query()->whereKey($data['location_id'])->active()->exists()) {
            return null;
        }

        return $data;
    }

    public static function id(Request $request): string
    {
        return self::fromRequest($request)['id'] ?? 'none';
    }

    public static function enableCookie(int $locationId, int $userId): SymfonyCookie
    {
        return Cookie::make(self::COOKIE, json_encode(['id' => Str::random(24), 'location_id' => $locationId, 'enabled_by' => $userId]), self::DAYS * 24 * 60, httpOnly: true, sameSite: 'lax');
    }

    public static function forgetCookie(): SymfonyCookie
    {
        return Cookie::forget(self::COOKIE);
    }
}
