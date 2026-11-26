<?php

namespace App\Services\Admin;

use App\Models\BackupRecord;
use App\Models\User;
use App\Services\Security\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;

class DatabaseBackupService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Create a Hostinger-safe, restorable gzip-compressed MySQL/SQLite logical SQL dump
     * stored strictly on private local storage (never inside public/) with SHA-256 verification.
     */
    public function createDatabaseBackup(?User $actor = null, ?string $notes = null): BackupRecord
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $pdo = $connection->getPdo();

        $filename = sprintf(
            'backups/syncviet-db-%s-%s.sql.gz',
            now()->format('Ymd-His'),
            Str::lower(Str::random(6)),
        );

        $disk = Storage::disk('local');
        $disk->makeDirectory('backups');
        $fullPath = $disk->path($filename);

        $gz = gzopen($fullPath, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Unable to open private backup archive for writing.');
        }

        try {
            gzwrite($gz, "-- SyncViet Logical Database Backup\n");
            gzwrite($gz, '-- Generated At: '.now()->toIso8601String()."\n");
            gzwrite($gz, "-- Driver: {$driver}\n");
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
            gzwrite($gz, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $this->dumpMysql($pdo, $gz);
            } else {
                $this->dumpGenericTables($pdo, $gz);
            }

            gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            gzclose($gz);
        }

        $sizeBytes = is_file($fullPath) ? (int) filesize($fullPath) : 0;
        $checksum = is_file($fullPath) ? (string) hash_file('sha256', $fullPath) : '';

        $record = new BackupRecord([
            'type' => 'database_sql_gz',
            'disk' => 'local',
            'path' => $filename,
            'size_bytes' => $sizeBytes,
            'checksum' => $checksum,
            'status' => 'completed',
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : 'Scheduled / CLI full logical SQL backup',
        ]);
        $record->created_by = $actor?->id;
        $record->created_at = now();
        $record->save();

        $this->auditLogger->log(
            'system.backup.database_created',
            $record,
            [
                'size_bytes' => $sizeBytes,
                'checksum' => $checksum,
                'driver' => $driver,
            ],
            $actor,
        );

        return $record;
    }

    /**
     * Prune expired local backups beyond retention days to protect Shared Hosting 50GB storage & inode caps.
     */
    public function pruneOldBackups(?int $retentionDays = null): int
    {
        if (! Schema::hasTable('backup_records')) {
            return 0;
        }

        $days = max(1, $retentionDays ?? (int) config('syncviet.backup_retention_days', 7));
        $cutoff = now()->subDays($days);

        // Always preserve at least the 3 newest completed backups regardless of age
        $protectedIds = BackupRecord::query()
            ->where('status', 'completed')
            ->latest('id')
            ->limit(3)
            ->pluck('id')
            ->all();

        $stale = BackupRecord::query()
            ->where('created_at', '<', $cutoff)
            ->when($protectedIds !== [], static fn ($q) => $q->whereNotIn('id', $protectedIds))
            ->get();

        $pruned = 0;
        foreach ($stale as $record) {
            if ($record->disk === 'local' && $record->path !== '') {
                Storage::disk('local')->delete($record->path);
            }
            $record->delete();
            $pruned++;
        }

        return $pruned;
    }

    /**
     * @param  resource  $gz
     */
    private function dumpMysql(PDO $pdo, mixed $gz): void
    {
        $stmt = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
        $tables = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_NUM) : [];

        foreach ($tables as $row) {
            $table = (string) $row[0];
            $quoted = '`'.str_replace('`', '``', $table).'`';

            $createStmt = $pdo->query("SHOW CREATE TABLE {$quoted}");
            $createRow = $createStmt !== false ? $createStmt->fetch(PDO::FETCH_NUM) : false;
            if (! is_array($createRow) || ! isset($createRow[1])) {
                continue;
            }

            gzwrite($gz, "-- Table structure for {$quoted}\n");
            gzwrite($gz, "DROP TABLE IF EXISTS {$quoted};\n");
            gzwrite($gz, (string) $createRow[1].";\n\n");

            $dataStmt = $pdo->query("SELECT * FROM {$quoted}");
            if ($dataStmt === false) {
                continue;
            }

            $batch = [];
            $columnsHeader = null;
            while (($record = $dataStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($columnsHeader === null) {
                    $cols = array_map(
                        static fn (string $c): string => '`'.str_replace('`', '``', $c).'`',
                        array_keys($record),
                    );
                    $columnsHeader = '('.implode(', ', $cols).')';
                }

                $values = [];
                foreach ($record as $val) {
                    if ($val === null) {
                        $values[] = 'NULL';
                    } elseif (is_int($val) || is_float($val)) {
                        $values[] = (string) $val;
                    } else {
                        $values[] = $pdo->quote((string) $val);
                    }
                }
                $batch[] = '('.implode(', ', $values).')';

                if (count($batch) >= 100) {
                    gzwrite($gz, "INSERT INTO {$quoted} {$columnsHeader} VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }

            if ($batch !== []) {
                gzwrite($gz, "INSERT INTO {$quoted} {$columnsHeader} VALUES\n".implode(",\n", $batch).";\n");
            }
            gzwrite($gz, "\n");
        }
    }

    /**
     * @param  resource  $gz
     */
    private function dumpGenericTables(PDO $pdo, mixed $gz): void
    {
        $stmt = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $tables = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($tables as $row) {
            $table = (string) $row['name'];
            $ddl = (string) ($row['sql'] ?? '');
            if ($ddl !== '') {
                gzwrite($gz, "DROP TABLE IF EXISTS \"{$table}\";\n{$ddl};\n");
            }

            $dataStmt = $pdo->query("SELECT * FROM \"{$table}\"");
            if ($dataStmt === false) {
                continue;
            }

            while (($record = $dataStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $cols = implode(', ', array_map(static fn (string $c): string => "\"{$c}\"", array_keys($record)));
                $vals = implode(', ', array_map(static fn (mixed $v): string => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($record)));
                gzwrite($gz, "INSERT INTO \"{$table}\" ({$cols}) VALUES ({$vals});\n");
            }
            gzwrite($gz, "\n");
        }
    }
}
