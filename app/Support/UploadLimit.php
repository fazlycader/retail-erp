<?php

namespace App\Support;

/**
 * The largest file this server accepts in a form, from php.ini (upload_max_filesize and
 * post_max_size). Shared with the front end so oversized files are caught before upload.
 */
final class UploadLimit
{
    public static function bytes(): int
    {
        $limits = array_filter([
            self::toBytes((string) ini_get('upload_max_filesize')),
            self::toBytes((string) ini_get('post_max_size')),
        ]);

        return $limits === [] ? PHP_INT_MAX : min($limits);
    }

    /**
     * The smaller of the server limit and an app limit given in kilobytes (Laravel's max: unit).
     */
    public static function kilobytes(int $appLimitKb): int
    {
        return min($appLimitKb, intdiv(self::bytes(), 1024));
    }

    public static function describe(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
