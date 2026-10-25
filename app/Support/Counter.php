<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free counters (invoice, GRN, transfer numbers…): returns the next number for a
 * (type, scope) row of the counters table and keeps it locked until the surrounding transaction
 * commits, so a rolled-back document gives its number back.
 *
 * MySQL: one INSERT … ON DUPLICATE KEY UPDATE takes the row's exclusive lock straight away.
 * "Insert-or-ignore, then SELECT … FOR UPDATE" deadlocks when several transactions create the
 * row at the same moment (each holds a shared lock on the new key and waits for the others).
 * Other drivers (SQLite in tests) use the simple path.
 */
final class Counter
{
    /**
     * @param  string  $type  what is numbered, e.g. "invoice"
     * @param  string  $scope  numbers restart per scope, e.g. "location:1" or "location:1:2026-10-23"
     */
    public static function next(string $type, string $scope = ''): int
    {
        $connection = DB::connection();

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Existing counter (the normal case): a plain UPDATE on the unique key locks just that row.
            // The upsert also gap-locks the end of the table, so two documents that each take several
            // numbers in one transaction (invoice + sync versions) would deadlock on it. The existence
            // check is a non-locking read: a failed locking UPDATE would gap-lock too.
            $exists = $connection->selectOne('SELECT 1 AS found FROM `counters` WHERE `type` = ? AND `scope` = ?', [$type, $scope]);

            if ($exists) {
                $connection->affectingStatement(
                    'UPDATE `counters` SET `last_number` = LAST_INSERT_ID(`last_number` + 1) WHERE `type` = ? AND `scope` = ?',
                    [$type, $scope],
                );

                return (int) $connection->selectOne('SELECT LAST_INSERT_ID() AS n')->n;
            }

            // First number for this type and scope. 1 affected row = new row (number 1); 2 = another
            // writer created it meanwhile and this incremented it (read it back).
            $affected = $connection->affectingStatement(
                'INSERT INTO `counters` (`type`, `scope`, `last_number`) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE `last_number` = LAST_INSERT_ID(`last_number` + 1)',
                [$type, $scope],
            );

            return $affected === 1 ? 1 : (int) $connection->selectOne('SELECT LAST_INSERT_ID() AS n')->n;
        }

        $key = ['type' => $type, 'scope' => $scope];

        DB::table('counters')->insertOrIgnore([...$key, 'last_number' => 0]);
        $counter = DB::table('counters')->where($key)->lockForUpdate()->first();
        DB::table('counters')->where('id', $counter->id)->update(['last_number' => $counter->last_number + 1]);

        return $counter->last_number + 1;
    }

    /** The last number handed out (0 when none yet). */
    public static function current(string $type, string $scope = ''): int
    {
        return (int) DB::table('counters')->where(['type' => $type, 'scope' => $scope])->value('last_number');
    }
}
