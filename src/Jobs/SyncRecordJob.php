<?php

namespace MylabDatabaseSync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MylabDatabaseSync\Services\DatabaseSyncService;

class SyncRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    protected $record;

    public function __construct($record)
    {
        $this->record = $record;
        $this->onQueue(config('database-sync.performance.queue_name'));
    }

    public function handle(DatabaseSyncService $syncService)
    {
        $syncService->syncRecord($this->record);
    }

    public function failed(\Throwable $exception)
    {
        \Log::channel(config('database-sync.monitoring.log_channel'))
            ->error("SyncRecordJob permanently failed", [
                'record_id' => $this->record->id,
                'table' => $this->record->table_name,
                'operation' => $this->record->operation,
                'error' => $exception->getMessage(),
            ]);
    }
}
