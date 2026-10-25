<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\CompanySetting;
use App\Models\User;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor login (Google Authenticator, Microsoft Authenticator, 1Password…) for any staff
 * member; required for admins and accountants (security.require_two_factor). Secrets are stored
 * encrypted; recovery codes are stored hashed and work once.
 */
final class TwoFactor
{
    public const RECOVERY_CODES = 8;

    public static function engine(): Google2FA
    {
        return new Google2FA;
    }

    public static function newSecret(): string
    {
        return self::engine()->generateSecretKey(32);
    }

    public static function qrSvg(User $user, string $secret): string
    {
        $issuer = CompanySetting::current()->name ?: config('app.name');
        $uri = self::engine()->getQRCodeUrl($issuer, $user->email, $secret);

        return (new QRCode(new QROptions(['outputBase64' => false, 'addQuietzone' => true])))->render($uri);
    }

    /** Checks a 6-digit code (±30 s) and refuses the same code twice. */
    public static function verify(User $user, ?string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);

        if (! $secret || strlen($code) !== 6) {
            return false;
        }

        $timestamp = self::engine()->verifyKeyNewer($secret, $code, Cache::get("2fa-used:{$user->id}"), 1);

        if ($timestamp === false) {
            return false;
        }

        Cache::put("2fa-used:{$user->id}", $timestamp === true ? self::engine()->getTimestamp() : $timestamp, now()->addMinutes(5));

        return true;
    }

    /**
     * New recovery codes: the plain codes (shown once) and their hashes (stored).
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    public static function recoveryCodes(): array
    {
        $plain = array_map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)), range(1, self::RECOVERY_CODES));

        return [$plain, array_map(fn ($code) => Hash::make($code), $plain)];
    }

    /** Uses up a recovery code if it matches. */
    public static function useRecoveryCode(User $user, string $code): bool
    {
        $code = Str::lower(trim($code));
        $hashes = $user->two_factor_recovery_codes ?? [];

        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    public static function required(User $user): bool
    {
        return in_array($user->role, [Role::Admin, Role::Accountant], true) && (bool) config('security.require_two_factor');
    }
}
