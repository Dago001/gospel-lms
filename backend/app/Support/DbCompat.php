<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The few places where PostgreSQL and MySQL/MariaDB differ. Everything else in the application is portable Eloquent/SQL.
 * The same integrity guarantees are kept on both engines: partial unique indexes, append-only tables, CHECK constraints.
 */
class DbCompat
{
    public static function mysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** JSON/TEXT columns cannot have a literal default on MySQL: leave them nullable there (models supply the '[]' default). */
    public static function jsonDefault(\Illuminate\Database\Schema\ColumnDefinition $column): \Illuminate\Database\Schema\ColumnDefinition
    {
        return self::mysql() ? $column->nullable() : $column->default('[]');
    }

    /**
     * Serialise concurrent work for one learner inside the current transaction (e.g. two quick exam starts, double submissions).
     * PostgreSQL: a transaction-scoped advisory lock. MySQL: a row lock on the learner's user row, released at commit.
     */
    public static function lockFor(string $key, string $userId): void
    {
        if (self::mysql()) {
            DB::table('users')->where('id', $userId)->lockForUpdate()->value('id');

            return;
        }
        DB::select('select pg_advisory_xact_lock(hashtext(?))', [$key]);
    }

    /** SQL for "the calendar day in Africa/Lagos" of a UTC timestamp column. Lagos is a fixed UTC+1 (no daylight saving). */
    public static function lagosDay(string $col): string
    {
        return self::mysql() ? "DATE(DATE_ADD($col, INTERVAL 1 HOUR))" : "date($col at time zone 'Africa/Lagos')";
    }

    public static function lagosMonth(string $col): string
    {
        return self::mysql() ? "DATE_FORMAT(DATE_ADD($col, INTERVAL 1 HOUR), '%Y-%m')" : "to_char($col at time zone 'Africa/Lagos', 'YYYY-MM')";
    }

    /** Portable "count of rows matching a condition" (an integer on both engines). */
    public static function countWhere(string $condition): string
    {
        return "count(case when $condition then 1 end)";
    }

    /** SQL for $startCol plus a number of minutes held in another column. */
    public static function addMinutes(string $startCol, string $minutesCol): string
    {
        return self::mysql() ? "DATE_ADD($startCol, INTERVAL $minutesCol MINUTE)" : "$startCol + ($minutesCol || ' minutes')::interval";
    }

    /** Case-insensitive LIKE operator for the current engine (MySQL's default collation is already case-insensitive). */
    public static function like(): string
    {
        return self::mysql() ? 'like' : 'ilike';
    }

    /**
     * UNIQUE over $cols but only for rows matching $where. PostgreSQL: a partial index. MySQL/MariaDB (no partial indexes): a stored
     * generated column that is NULL for rows outside the condition (NULLs never collide) with a UNIQUE index on it.
     *
     * @param  string[]  $cols
     */
    public static function partialUnique(string $table, string $index, array $cols, string $where): void
    {
        if (! self::mysql()) {
            DB::statement("CREATE UNIQUE INDEX $index ON $table (".implode(', ', $cols).") WHERE $where");

            return;
        }
        $col = $index.'_k';
        DB::statement("ALTER TABLE $table ADD COLUMN $col VARCHAR(500) GENERATED ALWAYS AS (IF($where, CONCAT_WS('|', ".implode(', ', $cols)."), NULL)) STORED, ADD UNIQUE INDEX $index ($col)");
    }

    /** Make a table append-only: UPDATE and DELETE are rejected by the database itself. */
    public static function appendOnly(string $table): void
    {
        if (! self::mysql()) {
            DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION {$table}_immutable() RETURNS trigger AS \$\$
BEGIN RAISE EXCEPTION '$table is append-only'; END; \$\$ LANGUAGE plpgsql;
CREATE TRIGGER {$table}_no_change BEFORE UPDATE OR DELETE ON $table
FOR EACH ROW EXECUTE FUNCTION {$table}_immutable();
SQL);

            return;
        }
        try {
            DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$table is append-only'");
            DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$table is append-only'");
        } catch (\Throwable $e) {
            // Shared hosts with binary logging can refuse trigger creation (needs SUPER or log_bin_trust_function_creators=1).
            // Never silent: the migration continues so the site can be installed, but the gap is logged loudly and shown in Admin > System.
            Log::critical("Could not create append-only triggers on $table: ".$e->getMessage());
            fwrite(STDERR, "\n  WARNING: append-only protection for $table could NOT be created (".$e->getMessage().")\n  Ask your host to set log_bin_trust_function_creators=1 or grant TRIGGER/SUPER, then run: php artisan gospel:install-protections\n");
        }
    }

    public static function dropAppendOnly(string $table): void
    {
        if (self::mysql()) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");

            return;
        }
        DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_change ON $table; DROP FUNCTION IF EXISTS {$table}_immutable();");
    }

    /** Names of append-only tables whose protection is present (used by the health screen). */
    public static function appendOnlyActive(): array
    {
        $tables = ['audit_logs', 'grade_change_logs', 'certificate_revocations'];
        $names = self::mysql()
            ? collect(DB::select('select trigger_name as n from information_schema.triggers where trigger_schema = ?', [DB::connection()->getDatabaseName()]))->pluck('n')->all()
            : collect(DB::select('select tgname from pg_trigger where not tgisinternal'))->pluck('tgname')->all();
        $out = [];
        foreach ($tables as $t) {
            $out[$t] = self::mysql() ? (in_array("{$t}_no_update", $names, true) && in_array("{$t}_no_delete", $names, true)) : in_array("{$t}_no_change", $names, true);
        }

        return $out;
    }
}
