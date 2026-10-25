<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Streams a mysqldump file (plain .sql or .sql.gz) into the current MySQL database, one
 * statement at a time (mysqldump writes one statement per line ending in ";"), so large dumps
 * don't need much memory. Used by demo:import and app:restore-backup.
 */
final class SqlImporter
{
    public static function import(string $path): int
    {
        $gz = str_ends_with($path, '.gz');
        $handle = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');

        if (! $handle) {
            throw new RuntimeException("Can't read {$path}");
        }

        $read = fn () => $gz ? gzgets($handle, 64 * 1024 * 1024) : fgets($handle, 64 * 1024 * 1024);
        $eof = fn () => $gz ? gzeof($handle) : feof($handle);
        $statement = '';
        $count = 0;

        DB::unprepared('SET FOREIGN_KEY_CHECKS=0');

        while (! $eof()) {
            $line = $read();

            if ($line === false || ($statement === '' && (trim($line) === '' || str_starts_with($line, '--')))) {
                continue;
            }

            $statement .= $line;

            if (str_ends_with(rtrim($line), ';')) {
                if (! self::skipped($statement)) {
                    DB::unprepared($statement);
                }

                $statement = '';
                $count++;
            }
        }

        $gz ? gzclose($handle) : fclose($handle);
        DB::unprepared('SET FOREIGN_KEY_CHECKS=1');

        return $count;
    }

    /**
     * Replication/binary-log statements MySQL 8 adds to dumps (GTID_PURGED, SQL_LOG_BIN): they
     * need SUPER privileges (not available on shared hosting) and aren't needed to restore data.
     */
    private static function skipped(string $statement): bool
    {
        return (bool) preg_match('/^\s*(SET\s+@@GLOBAL\.GTID_PURGED|SET\s+@@SESSION\.SQL_LOG_BIN|SET\s+@MYSQLDUMP_TEMP_LOG_BIN|SET\s+@@SESSION\.SQL_LOG_BIN\s*=\s*@MYSQLDUMP_TEMP_LOG_BIN)/i', $statement);
    }
}
