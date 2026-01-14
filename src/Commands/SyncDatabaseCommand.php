<?php

namespace MylabDatabaseSync\Commands;

use Illuminate\Console\Command;
use MylabDatabaseSync\Services\DatabaseSyncService;

class SyncDatabaseCommand extends Command
{
    protected $signature = 'sync:database 
                            {--daemon : Run as daemon (continuous mode)}
                            {--once : Process one batch and exit}';

    protected $description = 'Sync database changes from audit log';

    protected $syncService;

    public function __construct(DatabaseSyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    public function handle()
    {
        if ($this->option('daemon')) {
            return $this->runDaemon();
        }
        
        return $this->runOnce();
    }

    protected function runOnce()
    {
        $this->info("Processing pending sync records...");
        
        $stats = $this->syncService->processPendingSync();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Success', $stats['success']],
                ['Failed', $stats['failed']],
            ]
        );

        if ($stats['failed'] > 0) {
            $this->warn("Some records failed to sync. Check error log for details.");
        }

        return 0;
    }

    protected function runDaemon()
    {
        $interval = config('database-sync.sync_interval', 5);
        
        $this->info("Starting daemon mode (interval: {$interval}s)...");
        $this->info("Press Ctrl+C to stop");
        $this->line('');

        $iteration = 0;
        
        while (true) {
            $iteration++;
            $startTime = microtime(true);
            
            $stats = $this->syncService->processPendingSync();
            
            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            
            // Get overall stats
            $overall = $this->syncService->getStats();
            
            $this->output->write("\r\033[K"); // Clear line
            $this->output->write(sprintf(
                "[%s] Iteration: %d | Processed: %d | Success: %d | Failed: %d | Pending: %d | Time: %sms",
                date('H:i:s'),
                $iteration,
                $stats['processed'],
                $stats['success'],
                $stats['failed'],
                $overall['pending'],
                $elapsed
            ));

            if ($stats['failed'] > 0 && config('database-sync.monitoring.enabled')) {
                $this->line('');
                $this->warn("  └─ Failed records: " . $stats['failed']);
            }

            // Check memory usage
            $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
            $memoryLimit = config('database-sync.performance.memory_limit');
            
            if ($memoryUsage > $memoryLimit) {
                $this->line('');
                $this->error("Memory limit exceeded ({$memoryUsage}MB > {$memoryLimit}MB). Exiting...");
                return 1;
            }

            sleep($interval);
        }
    }
}
