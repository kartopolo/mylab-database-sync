<?php

namespace MylabDatabaseSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupAuditLogCommand extends Command
{
    protected $signature = 'sync:cleanup 
                            {--days= : Days to keep (default from config)}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Cleanup old synced records from audit log';

    public function handle()
    {
        $connection = config('database-sync.source_connection');
        $auditTable = config('database-sync.audit_table');
        $days = $this->option('days') ?? config('database-sync.cleanup.retention_days', 7);
        $dryRun = $this->option('dry-run');
        
        $this->info("Cleaning up audit log records older than {$days} days...");
        
        $cutoffDate = now()->subDays($days);
        
        // Count records to be deleted
        $count = DB::connection($connection)
            ->table($auditTable)
            ->where('synced', true)
            ->where('created_at', '<', $cutoffDate)
            ->count();
        
        if ($count === 0) {
            $this->info("No records to cleanup.");
            return 0;
        }

        if ($dryRun) {
            $this->warn("DRY RUN: Would delete {$count} records.");
            return 0;
        }

        if (!$this->confirm("Delete {$count} synced records?", true)) {
            $this->info("Cleanup cancelled.");
            return 0;
        }

        // Delete in batches to avoid locking table for too long
        $batchSize = 1000;
        $deleted = 0;
        
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        while ($deleted < $count) {
            $batch = DB::connection($connection)
                ->table($auditTable)
                ->where('synced', true)
                ->where('created_at', '<', $cutoffDate)
                ->limit($batchSize)
                ->delete();
            
            if ($batch === 0) {
                break;
            }
            
            $deleted += $batch;
            $bar->advance($batch);
            
            usleep(100000); // Sleep 100ms between batches
        }

        $bar->finish();
        $this->line('');
        $this->info("Cleanup completed! Deleted {$deleted} records.");

        return 0;
    }
}
