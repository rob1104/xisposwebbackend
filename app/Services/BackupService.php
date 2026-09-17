<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Models\DatabaseBackup;
use Exception;

class BackupService
{
    public function detectEngineAndVersion(): array
    {
        try {
            $versionResult = DB::select('SELECT VERSION() as v')[0]->v;
            $driver = 'mysql';

            if (stripos($versionResult, 'MariaDB') !== false) {
                $driver = 'mariadb';
            }

            return [
                'driver' => $driver,
                'version' => $versionResult,
            ];
        } catch (Exception $e) {
            return [
                'driver' => 'unknown',
                'version' => 'unknown',
            ];
        }
    }

    protected function findDumpCommand($driver): string
    {
        if ($driver === 'mariadb') {
            $process = new Process(['mariadb-dump', '--version']);
            $process->run();
            if ($process->isSuccessful()) return 'mariadb-dump';
        }

        $process = new Process(['mysqldump', '--version']);
        $process->run();
        if ($process->isSuccessful()) return 'mysqldump';

        return 'mysqldump';
    }

    public function executeBackup(DatabaseBackup $backup): void
    {
        try {
            $backup->update([
                'status' => 'running',
                'started_at' => now(),
            ]);

            $dbName = config('database.connections.mysql.database');
            $dbUser = config('database.connections.mysql.username');
            $dbPass = config('database.connections.mysql.password');
            $dbHost = config('database.connections.mysql.host');
            $dbPort = config('database.connections.mysql.port');

            $info = $this->detectEngineAndVersion();
            $driver = $info['driver'];
            $version = $info['version'];
            
            $backup->update([
                'database_name' => $dbName,
                'driver' => $driver,
                'database_version' => $version,
            ]);

            $dumpCommand = $this->findDumpCommand($driver);
            $tempSqlFile = storage_path('app/private/temp_backup_' . $backup->id . '.sql');

            $args = [
                $dumpCommand,
                '--user=' . $dbUser,
                '--host=' . $dbHost,
                '--port=' . $dbPort,
                '--routines',
                '--triggers',
            ];

            if ($dumpCommand === 'mysqldump') {
                $args[] = '--column-statistics=0';
            }

            $args[] = $dbName;
            $args[] = '--result-file=' . $tempSqlFile;

            // Secure way to pass password without showing in process list
            $env = array_merge($_SERVER, $_ENV);
            $env['MYSQL_PWD'] = $dbPass;

            $process = new Process($args, null, $env);
            $process->setTimeout(3600); // 1 hour timeout
            $process->run();

            if (!$process->isSuccessful()) {
                if (strpos($process->getErrorOutput(), 'Unknown variable') !== false && strpos($process->getErrorOutput(), 'column-statistics') !== false) {
                    $args = array_filter($args, fn($a) => $a !== '--column-statistics=0');
                    $process = new Process($args, null, $env);
                    $process->setTimeout(3600);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        throw new ProcessFailedException($process);
                    }
                } else {
                    throw new ProcessFailedException($process);
                }
            }

            $filename = 'backup_' . date('Y_m_d_His') . '.sql.gz';
            $path = 'private/backups/' . $filename;
            
            Storage::disk('local')->makeDirectory('private/backups');
            
            // Generate GZ
            $source = fopen($tempSqlFile, 'rb');
            $dest = Storage::disk('local')->path($path);
            $destStream = fopen($dest, 'wb');
            stream_filter_append($destStream, 'zlib.deflate', STREAM_FILTER_WRITE, -1);
            stream_copy_to_stream($source, $destStream);
            fclose($source);
            fclose($destStream);

            @unlink($tempSqlFile);

            $backup->update([
                'status' => 'completed',
                'finished_at' => now(),
                'filename' => $filename,
                'path' => $path,
                'size' => Storage::disk('local')->size($path),
            ]);

        } catch (Exception $e) {
            $backup->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => substr($e->getMessage(), 0, 1000),
            ]);
            
            if (isset($tempSqlFile) && file_exists($tempSqlFile)) {
                @unlink($tempSqlFile);
            }
            throw $e;
        }
    }
}
