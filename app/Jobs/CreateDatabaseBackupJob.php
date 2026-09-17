<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\DatabaseBackup;
use App\Services\BackupService;

class CreateDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    protected $backup;

    public function __construct(DatabaseBackup $backup)
    {
        $this->backup = $backup;
    }

    public function handle(BackupService $backupService): void
    {
        $backupService->executeBackup($this->backup);
    }
}
