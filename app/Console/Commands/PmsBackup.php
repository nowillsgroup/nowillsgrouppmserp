<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class PmsBackup extends Command
{
    protected $signature = 'pms:backup {--hotel= : Export only one hotel (id)} {--keep=14 : Days of backups to keep}';

    protected $description = 'Create an SQL backup of the PMS database (pure PHP, no mysqldump needed)';

    public function handle(): int
    {
        $file = $this->option('hotel') ? BackupService::hotel((int) $this->option('hotel')) : BackupService::full();
        $pruned = BackupService::prune((int) $this->option('keep'));
        $this->info('Backup written: '.$file.' ('.round(filesize($file) / 1024, 1).' KB)');
        if ($pruned) {
            $this->line("Pruned $pruned old backup(s).");
        }

        return self::SUCCESS;
    }
}
