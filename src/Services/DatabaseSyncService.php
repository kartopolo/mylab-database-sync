<?php

namespace MylabDatabaseSync\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MylabDatabaseSync\Jobs\SyncRecordJob;

class DatabaseSyncService
{
    protected $sourceConnection;
    protected $targetConnection;
    protected $auditTable;
    protected $typeMapper;
    protected $batchSize;
    protected $useQueue;

    public function __construct(TypeMapperService $typeMapper)
    {
        $this->sourceConnection = config('database-sync.source_connection');
        $this->targetConnection = config('database-sync.target_connection');
        $this->auditTable = config('database-sync.audit_table');
        $this->typeMapper = $typeMapper;
        $this->batchSize = config('database-sync.batch_size', 100);
        $this->useQueue = config('database-sync.performance.use_queue', true);
    }

    /**
     * Process pending sync records
     */
    public function processPendingSync(): array
    {
        $stats = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $pending = DB::connection($this->sourceConnection)
            ->table($this->auditTable)
            ->where('synced', 0)  // Use integer 0 for MySQL tinyint
            ->where('retry_count', '<', config('database-sync.retry.max_attempts'))
            ->orderBy('id')
            ->limit($this->batchSize)
            ->get();

        foreach ($pending as $record) {
            $stats['processed']++;
            
            if ($this->useQueue) {
                SyncRecordJob::dispatch($record)
                    ->onQueue(config('database-sync.performance.queue_name'));
                $stats['success']++;
            } else {
                $result = $this->syncRecord($record);
                $result ? $stats['success']++ : $stats['failed']++;
                
                if (!$result) {
                    $stats['errors'][] = [
                        'id' => $record->id,
                        'table' => $record->table_name,
                        'operation' => $record->operation,
                    ];
                }
            }
        }

        return $stats;
    }

    /**
     * Sync single record
     */
    public function syncRecord($record): bool
    {
        try {
            echo "[DEBUG] Start syncing ID {$record->id}: {$record->operation} on {$record->table_name}\n";
            
            DB::connection($this->targetConnection)->beginTransaction();

            switch ($record->operation) {
                case 'INSERT':
                    $this->handleInsert($record);
                    break;
                case 'UPDATE':
                    $this->handleUpdate($record);
                    break;
                case 'DELETE':
                    $this->handleDelete($record);
                    break;
            }

            DB::connection($this->targetConnection)->commit();
            
            echo "[DEBUG] Commit OK, marking as synced...\n";

            // Mark as synced
            $affected = DB::connection($this->sourceConnection)
                ->table($this->auditTable)
                ->where('id', $record->id)
                ->update([
                    'synced' => 1,  // Use integer 1 for MySQL tinyint compatibility
                    'synced_at' => now(),
                    'error_message' => null,
                ]);
            
            echo "[DEBUG] Synced status updated, affected: {$affected}\n";

            if (config('database-sync.monitoring.enabled')) {
                Log::channel(config('database-sync.monitoring.log_channel'))
                    ->info("Synced {$record->operation} on {$record->table_name}", [
                        'record_id' => $record->record_id,
                    ]);
            }

            return true;

        } catch (\Exception $e) {
            echo "[ERROR] Exception: " . $e->getMessage() . "\n";
            echo "[ERROR] File: " . $e->getFile() . ":" . $e->getLine() . "\n";
            
            DB::connection($this->targetConnection)->rollBack();

            // Update retry count and error
            DB::connection($this->sourceConnection)
                ->table($this->auditTable)
                ->where('id', $record->id)
                ->update([
                    'retry_count' => DB::raw('retry_count + 1'),
                    'error_message' => substr($e->getMessage(), 0, 1000),
                ]);

            if (config('database-sync.monitoring.enabled')) {
                Log::channel(config('database-sync.monitoring.log_channel'))
                    ->error("Failed to sync {$record->operation} on {$record->table_name}", [
                        'record_id' => $record->record_id,
                        'error' => $e->getMessage(),
                    ]);
            }

            return false;
        }
    }

    /**
     * Handle INSERT operation
     */
    protected function handleInsert($record): void
    {
        $data = json_decode($record->new_data, true);
        
        // Get column types from target database
        $columns = DB::connection($this->targetConnection)
            ->select("SELECT column_name, data_type, udt_name 
                     FROM information_schema.columns 
                     WHERE table_schema = 'public' 
                     AND table_name = ?", [$record->table_name]);
        
        $columnTypes = [];
        foreach ($columns as $col) {
            $columnTypes[$col->column_name] = [
                'type' => $col->data_type,
                'udt_name' => $col->udt_name,
            ];
        }
        
        // Sanitize data before insert
        foreach ($data as $column => &$value) {
            if (isset($columnTypes[$column]) && $value !== null) {
                $value = $this->typeMapper->sanitizeValue($value, $columnTypes[$column], $record->table_name);
            }
        }
        
        DB::connection($this->targetConnection)
            ->table($record->table_name)
            ->insertOrIgnore($data);
    }

    /**
     * Handle UPDATE operation
     */
    protected function handleUpdate($record): void
    {
        $oldData = json_decode($record->old_data, true);
        $newData = json_decode($record->new_data, true);
        
        // Get column types from target database
        $columns = DB::connection($this->targetConnection)
            ->select("SELECT column_name, data_type, udt_name 
                     FROM information_schema.columns 
                     WHERE table_schema = 'public' 
                     AND table_name = ?", [$record->table_name]);
        
        $columnTypes = [];
        foreach ($columns as $col) {
            $columnTypes[$col->column_name] = [
                'type' => $col->data_type,
                'udt_name' => $col->udt_name,
            ];
        }
        
        // Sanitize new data before update
        foreach ($newData as $column => &$value) {
            if (isset($columnTypes[$column]) && $value !== null) {
                $value = $this->typeMapper->sanitizeValue($value, $columnTypes[$column], $record->table_name);
            }
        }
        
        // Build WHERE clause from old PK values
        $query = DB::connection($this->targetConnection)
            ->table($record->table_name);
        
        // Try to get primary key from record_id (format: pk1:value1,pk2:value2)
        if ($record->record_id) {
            $pkPairs = explode(',', $record->record_id);
            foreach ($pkPairs as $pair) {
                [$key, $value] = explode(':', $pair, 2);
                $query->where($key, $value);
            }
        } else {
            // Fallback: use all old data as WHERE clause
            foreach ($oldData as $key => $value) {
                $query->where($key, $value);
            }
        }
        
        $query->update($newData);
    }

    /**
     * Handle DELETE operation
     */
    protected function handleDelete($record): void
    {
        $oldData = json_decode($record->old_data, true);
        
        // Build WHERE clause from old PK values
        $query = DB::connection($this->targetConnection)
            ->table($record->table_name);
        
        if ($record->record_id) {
            $pkPairs = explode(',', $record->record_id);
            foreach ($pkPairs as $pair) {
                [$key, $value] = explode(':', $pair, 2);
                $query->where($key, $value);
            }
        } else {
            // Fallback: use all old data as WHERE clause
            foreach ($oldData as $key => $value) {
                $query->where($key, $value);
            }
        }
        
        $query->delete();
    }

    /**
     * Get sync statistics
     */
    public function getStats(): array
    {
        $total = DB::connection($this->sourceConnection)
            ->table($this->auditTable)
            ->count();
        
        $pending = DB::connection($this->sourceConnection)
            ->table($this->auditTable)
            ->where('synced', false)
            ->count();
        
        $failed = DB::connection($this->sourceConnection)
            ->table($this->auditTable)
            ->where('synced', false)
            ->where('retry_count', '>=', config('database-sync.retry.max_attempts'))
            ->count();
        
        return [
            'total' => $total,
            'synced' => $total - $pending,
            'pending' => $pending,
            'failed' => $failed,
        ];
    }
}
