<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\DB;
use Throwable;

class PostgresSequenceSynchronizer
{
    /**
     * @param  array<int, string>  $tables
     */
    public function syncTables(array $tables): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $synchronized = false;

        foreach ($tables as $table) {
            if (! $this->isValidIdentifier($table)) {
                continue;
            }

            $sequence = "{$table}_id_seq";
            $sequenceReference = "public.{$sequence}";
            $resolved = DB::selectOne('SELECT to_regclass(?) AS seq', [$sequenceReference]);
            $resolvedSequence = is_object($resolved) ? (string) ($resolved->seq ?? '') : '';

            if ($resolvedSequence === '') {
                continue;
            }

            $tableName = str_replace('"', '""', $table);
            $sequenceName = str_replace("'", "''", $sequence);

            try {
                DB::statement(
                    "SELECT setval('{$sequenceName}', GREATEST(COALESCE((SELECT MAX(id) FROM \"{$tableName}\"), 1), 1), true)"
                );

                $synchronized = true;
            } catch (Throwable) {
                continue;
            }
        }

        return $synchronized;
    }

    private function isValidIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }
}
