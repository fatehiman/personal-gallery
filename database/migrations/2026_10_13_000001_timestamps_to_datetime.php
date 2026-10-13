<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Every TIMESTAMP column becomes DATETIME.
 *
 * The app writes UTC values, but the MySQL session used the server time zone (SYSTEM = Europe/Berlin on ger1).
 * A TIMESTAMP is converted from the session zone to UTC, so a UTC value that falls in the missing summer-time hour
 * of that zone was refused: "Incorrect datetime value: '2011-03-27 02:50:13' for column 'file_mtime'". The crawler
 * was stuck on that folder. TIMESTAMP also can not hold dates before 1970 or after 2038 (old or broken file dates).
 * DATETIME stores the value as it is, without any time zone conversion.
 *
 * The conversion runs with the session zone the data was written with (the server zone), so every value stays
 * exactly what the app wrote. After this, config/database.php sets the session zone to UTC ('+00:00').
 *
 * MySQL only (the tests use SQLite, which has no TIMESTAMP type). Can be run again: it only finds TIMESTAMP columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $columns = DB::select(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS def, EXTRA AS extra
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp'
             ORDER BY TABLE_NAME, ORDINAL_POSITION"
        );
        if (! $columns) {
            return;
        }
        $zone = DB::selectOne('SELECT @@session.time_zone AS z')->z;
        DB::statement('SET time_zone = @@global.time_zone');
        try {
            $byTable = [];
            foreach ($columns as $col) {
                $sql = "MODIFY `$col->c` DATETIME ".($col->nullable === 'YES' ? 'NULL' : 'NOT NULL');
                if ($col->def !== null) {
                    $sql .= stripos($col->def, 'CURRENT_TIMESTAMP') === 0
                        ? ' DEFAULT CURRENT_TIMESTAMP'
                        : ' DEFAULT '.DB::getPdo()->quote($col->def);
                }
                if (stripos((string) $col->extra, 'on update CURRENT_TIMESTAMP') !== false) {
                    $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
                }
                $byTable[$col->t][] = $sql;
            }
            foreach ($byTable as $table => $parts) {
                DB::statement("ALTER TABLE `$table` ".implode(', ', $parts));
            }
        } finally {
            DB::statement('SET time_zone = '.DB::getPdo()->quote($zone));
        }
    }

    public function down(): void
    {
        // Not reversible: going back to TIMESTAMP would bring the time zone problem back.
    }
};
