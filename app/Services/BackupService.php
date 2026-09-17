<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\DbDumper\Databases\MySql;
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

            $tempSqlFile = storage_path('app/private/temp_backup_' . $backup->id . '.sql');

            // Utilizando Spatie DbDumper que es robusto y probado
            // Inicialmente NO usamos doNotUseColumnStatistics porque versiones antiguas y MariaDB fallan al no reconocer el flag
            $dumper = MySql::create()
                ->setDbName($dbName)
                ->setUserName($dbUser)
                ->setPassword($dbPass)
                ->setHost($dbHost)
                ->setPort($dbPort)
                ->addExtraOption('--routines')
                ->addExtraOption('--triggers');

            $configureBinaryPath = function($d) {
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    // Windows sockets fail in mysqldump under PHP if SystemRoot is missing
                    if (!getenv('SystemRoot')) putenv('SystemRoot=C:\Windows');
                    if (!getenv('SYSTEMROOT')) putenv('SYSTEMROOT=C:\Windows');
                    if (!getenv('COMSPEC')) putenv('COMSPEC=C:\Windows\System32\cmd.exe');
                    
                    $possiblePaths = [
                        'D:\\xampp82\\mysql\\bin',
                        'C:\\xampp\\mysql\\bin',
                        'D:\\xampp\\mysql\\bin',
                    ];
                    foreach ($possiblePaths as $path) {
                        if (file_exists($path . '\\mysqldump.exe') || file_exists($path . '\\mariadb-dump.exe')) {
                            $d->setDumpBinaryPath($path);
                            break;
                        }
                    }
                }
            };

            $configureBinaryPath($dumper);

            try {
                $dumper->dumpToFile($tempSqlFile);
            } catch (Exception $e) {
                // Si el error es debido a que el cliente es MySQL 8+ y el servidor 5.7-, fallará pidiendo apagar column_statistics
                if (strpos($e->getMessage(), 'COLUMN_STATISTICS') !== false || strpos($e->getMessage(), 'column-statistics') !== false) {
                    $dumper = MySql::create()
                        ->setDbName($dbName)
                        ->setUserName($dbUser)
                        ->setPassword($dbPass)
                        ->setHost($dbHost)
                        ->setPort($dbPort)
                        ->doNotUseColumnStatistics()
                        ->addExtraOption('--routines')
                        ->addExtraOption('--triggers');
                        
                    $configureBinaryPath($dumper);
                    $dumper->dumpToFile($tempSqlFile);
                } else {
                    throw $e;
                }
            }

            $filename = 'backup_' . date('Y_m_d_His') . '.zip';
            $path = 'private/backups/' . $filename;
            
            Storage::disk('local')->makeDirectory('private/backups');
            $dest = Storage::disk('local')->path($path);
            
            // Generar ZIP directamente en PHP
            $zip = new \ZipArchive();
            if ($zip->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                // Agregamos el archivo SQL dentro del ZIP
                $zip->addFile($tempSqlFile, 'database_backup_' . date('Y_m_d_His') . '.sql');
                $zip->close();
            } else {
                throw new Exception("No se pudo crear el archivo ZIP de respaldo.");
            }

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
