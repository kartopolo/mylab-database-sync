<?php

namespace MylabDatabaseSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MylabDatabaseSync\Services\TableDiscoveryService;
use MylabDatabaseSync\Services\TypeMapperService;

class InitialSyncCommand extends Command
{
    protected $signature = 'sync:initial 
                            {--table= : Sync specific table only}
                            {--create-tables : Auto-create tables in target database}
                            {--drop-target : Drop all tables in target database before sync}
                            {--batch=1000 : Batch size for data transfer}
                            {--resume : Resume from last failed or incomplete sync}
                            {--retry-errors : Retry only failed batches from error log}
                            {--reset-progress : Reset sync progress for all or specific table}';

    protected $description = 'Perform initial full data sync from source to target';

    protected $discovery;
    protected $typeMapper;

    protected $shutdownRequested = false;
    
    public function __construct(TableDiscoveryService $discovery, TypeMapperService $typeMapper)
    {
        parent::__construct();
        $this->discovery = $discovery;
        $this->typeMapper = $typeMapper;
        
        // Register graceful shutdown handler
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);
    }
    
    public function handleShutdown($signo)
    {
        $this->shutdownRequested = true;
        $this->warn("\nShutdown signal received. Finishing current batch...");
    }

    public function handle()
    {
        $sourceConnection = config('database-sync.source_connection');
        $targetConnection = config('database-sync.target_connection');
        $batchSize = $this->option('batch');
        
        // Handle drop-target option
        if ($this->option('drop-target')) {
            if ($this->confirm('This will DROP ALL TABLES in target database. Continue?', false)) {
                $this->dropAllTargetTables($targetConnection);
            } else {
                $this->warn('Drop cancelled.');
                return 1;
            }
        }
        
        // Handle reset-progress option
        if ($this->option('reset-progress')) {
            return $this->resetProgress($sourceConnection);
        }
        
        // Handle retry-errors option
        if ($this->option('retry-errors')) {
            return $this->retryFailedBatches($sourceConnection, $targetConnection, $batchSize);
        }
        
        $tables = $this->option('table') 
            ? [$this->option('table')]
            : $this->discovery->sortByDependency($this->discovery->getAllTables());

        $this->info("Starting initial sync for " . count($tables) . " tables...");
        $this->line('');

        foreach ($tables as $table) {
            // Check if we should resume this table
            $resumeOffset = 0;
            if ($this->option('resume')) {
                $resumeOffset = $this->getResumeOffset($sourceConnection, $table);
            }
            
            $this->syncTable($sourceConnection, $targetConnection, $table, $batchSize, $resumeOffset);
        }

        $this->line('');
        $this->info("Initial sync completed!");
        
        // Show error summary
        $this->showErrorSummary($sourceConnection);
        
        // Show progress summary
        $this->showProgressSummary($sourceConnection);

        return 0;
    }

    protected function syncTable($sourceConnection, $targetConnection, $table, $batchSize, $resumeOffset = 0)
    {
        $this->line("Syncing table: <fg=cyan>{$table}</>");

        // Check if table exists in target
        $targetExists = DB::connection($targetConnection)
            ->getSchemaBuilder()
            ->hasTable($table);

        if (!$targetExists) {
            if ($this->option('create-tables')) {
                $this->createTable($sourceConnection, $targetConnection, $table);
            } else {
                $this->warn("  └─ Table does not exist in target. Use --create-tables to auto-create.");
                return;
            }
        }

        $columnsMeta = $this->discovery->getTableColumns($table);
        $columnsByName = [];
        $blobColumns = [];

        foreach ($columnsMeta as $columnMeta) {
            $columnsByName[$columnMeta['COLUMN_NAME']] = $columnMeta;
            // Detect BLOB/BINARY columns
            if (in_array(strtoupper($columnMeta['DATA_TYPE']), ['BLOB', 'LONGBLOB', 'MEDIUMBLOB', 'TINYBLOB', 'BINARY', 'VARBINARY'])) {
                $blobColumns[] = $columnMeta['COLUMN_NAME'];
            }
        }

        // Get total rows
        $totalRows = DB::connection($sourceConnection)
            ->table($table)
            ->count();

        if ($totalRows === 0) {
            $this->info("  └─ Empty table, skipping.");
            $this->updateProgress($sourceConnection, $table, 'completed', $totalRows, 0, 0, 0);
            return;
        }
        
        // Initialize or update progress
        $this->initProgress($sourceConnection, $table, $totalRows, $batchSize, $resumeOffset);

        $this->info("  └─ Total rows: {$totalRows}");
        if ($resumeOffset > 0) {
            $this->warn("  └─ Resuming from offset: {$resumeOffset}");
        }

        $bar = $this->output->createProgressBar($totalRows);
        $bar->start();
        
        // Advance bar to resume position
        if ($resumeOffset > 0) {
            $bar->setProgress($resumeOffset);
        }

        $offset = $resumeOffset;
        $synced = 0;
        $failed = 0;
        
        // Mark as in_progress
        $this->updateProgress($sourceConnection, $table, 'in_progress', $totalRows, $offset, $failed, $offset);

        while ($offset < $totalRows) {
            // Check for graceful shutdown
            if ($this->shutdownRequested) {
                $this->warn("Sync interrupted. Progress saved at offset {$offset}.");
                $this->updateProgress($sourceConnection, $table, 'in_progress', $totalRows, $synced, $failed, $offset);
                return 1;
            }
            
            $rows = DB::connection($sourceConnection)
                ->table($table)
                ->offset($offset)
                ->limit($batchSize)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            // Convert rows to array and clean values with sanitization
            $data = $rows->map(function ($row) use ($columnsByName, $blobColumns, $table) {
                $rowArray = (array) $row;
                $mapped = [];

                foreach ($rowArray as $key => $value) {
                    // Handle BLOB columns - convert to hex string for PostgreSQL bytea
                    if (in_array($key, $blobColumns) && $value !== null) {
                        $mapped[$key] = DB::raw("decode('" . bin2hex($value) . "', 'hex')");
                    } else {
                        // Use sanitizeValue to fix invalid values
                        $mapped[$key] = $this->typeMapper->sanitizeValue($value, $columnsByName[$key] ?? [], $table);
                    }
                }

                return $mapped;
            })->toArray();

            // Insert batch with fallback to row-by-row on error
            try {
                DB::connection($targetConnection)
                    ->table($table)
                    ->insert($data);
                
                $synced += count($data);
                
                // Mark previous error as resolved if this batch was in error log
                $this->markBatchResolved($sourceConnection, $table, $offset);
                
            } catch (\Exception $e) {
                $this->line('');
                $this->warn("  └─ Batch insert failed: " . substr($e->getMessage(), 0, 100));
                $this->info("  └─ Retrying row-by-row with aggressive sanitization...");
                
                // Try inserting rows one by one with more aggressive sanitization
                $rowSuccess = 0;
                $rowFailed = 0;
                
                foreach ($data as $idx => $singleRow) {
                    $maxRetries = 3;
                    $retryCount = 0;
                    $inserted = false;
                    
                    while ($retryCount < $maxRetries && !$inserted) {
                        try {
                            DB::connection($targetConnection)
                                ->table($table)
                                ->insert($singleRow);
                            $rowSuccess++;
                            $synced++;
                            $inserted = true;
                        } catch (\Exception $rowException) {
                            $retryCount++;
                            
                            if ($retryCount < $maxRetries) {
                                // Exponential backoff: 0.1s, 0.2s, 0.4s
                                usleep(100000 * pow(2, $retryCount - 1));
                            } else {
                                // Max retries exceeded, log error
                                $this->logBatchError($sourceConnection, $table, $offset + $idx, 1, $rowException, $singleRow);
                                $rowFailed++;
                            }
                        }
                    }
                }
                
                if ($rowSuccess > 0) {
                    $this->info("  └─ Saved {$rowSuccess}/{" . count($data) . "} rows individually");
                }
                
                if ($rowFailed > 0) {
                    $this->warn("  └─ Failed {$rowFailed} rows (logged to error table)");
                    $failed += $rowFailed;
                }
            }

            $bar->advance(count($data));
            $offset += $batchSize;
            
            // Update progress every batch for accurate resume
            $this->updateProgress($sourceConnection, $table, 'in_progress', $totalRows, $synced, $failed, $offset);
        }

        $bar->finish();
        $this->line('');
        $this->info("  └─ Synced {$synced} rows");
        if ($failed > 0) {
            $this->warn("  └─ Failed {$failed} batches");
        }
        $this->line('');
        
        // Mark as completed
        $finalStatus = $failed > 0 ? 'failed' : 'completed';
        $this->updateProgress($sourceConnection, $table, $finalStatus, $totalRows, $synced, $failed, $offset);
    }

    protected function createTable($sourceConnection, $targetConnection, $table)
    {
        $this->info("  └─ Creating table in target database...");

        $columns = $this->discovery->getTableColumns($table);
        $primaryKeys = $this->discovery->getPrimaryKeys($table);
        $foreignKeys = $this->discovery->getForeignKeys($table);

        // Build CREATE TABLE statement
        $columnDefinitions = [];
        
        foreach ($columns as $column) {
            $type = $this->typeMapper->mapType($column);
            $isNullable = $this->typeMapper->isNullable($column) || $this->typeMapper->shouldForceNullable($column);
            $nullable = $isNullable ? '' : ' NOT NULL';
            $defaultValue = $this->typeMapper->getDefault($column);
            $defaultClause = $defaultValue !== null ? " DEFAULT {$defaultValue}" : '';

            $columnDefinitions[] = "\"{$column['COLUMN_NAME']}\" {$type}{$nullable}{$defaultClause}";
        }

        // Add primary key
        if (!empty($primaryKeys)) {
            $pkColumns = implode('", "', $primaryKeys);
            $columnDefinitions[] = "PRIMARY KEY (\"{$pkColumns}\")";
        }

        $sql = "CREATE TABLE IF NOT EXISTS \"{$table}\" (\n  " 
             . implode(",\n  ", $columnDefinitions) 
             . "\n)";

        try {
            DB::connection($targetConnection)->statement($sql);
            $this->info("  └─ Table created successfully");
        } catch (\Exception $e) {
            $this->error("  └─ Failed to create table: " . $e->getMessage());
            throw $e;
        }
    }
    
    protected function logBatchError($connection, $table, $offset, $batchSize, $exception, $sampleRow)
    {
        try {
            // Extract failed columns from error message
            $failedColumns = $this->extractFailedColumns($exception->getMessage());
            
            DB::connection($connection)->table('sync_error_log')->insert([
                'table_name' => $table,
                'batch_offset' => $offset,
                'batch_size' => $batchSize,
                'error_message' => substr($exception->getMessage(), 0, 5000),
                'failed_columns' => json_encode($failedColumns),
                'sample_data' => json_encode(array_slice($sampleRow, 0, 10)), // First 10 columns only
                'resolved' => false,
                'error_at' => now(),
            ]);
        } catch (\Exception $e) {
            $this->warn("  └─ Could not log error: " . $e->getMessage());
        }
    }
    
    protected function markBatchResolved($connection, $table, $offset)
    {
        try {
            DB::connection($connection)
                ->table('sync_error_log')
                ->where('table_name', $table)
                ->where('batch_offset', $offset)
                ->where('resolved', false)
                ->update([
                    'resolved' => true,
                    'resolved_at' => now(),
                ]);
        } catch (\Exception $e) {
            // Silently fail - error log might not exist yet
        }
    }
    
    protected function extractFailedColumns($errorMessage)
    {
        $columns = [];
        
        // Match column names in PostgreSQL error messages
        if (preg_match('/column "([^"]+)"/', $errorMessage, $matches)) {
            $columns[] = $matches[1];
        }
        
        // Match columns in "Key (col1, col2)" format
        if (preg_match('/Key \(([^)]+)\)/', $errorMessage, $matches)) {
            $cols = explode(',', $matches[1]);
            foreach ($cols as $col) {
                $columns[] = trim($col);
            }
        }
        
        return array_unique($columns);
    }
    
    protected function retryFailedBatches($sourceConnection, $targetConnection, $batchSize)
    {
        $this->info("Retrying failed batches from error log...");
        $this->line('');
        
        $errors = DB::connection($sourceConnection)
            ->table('sync_error_log')
            ->where('resolved', false)
            ->orderBy('table_name')
            ->orderBy('batch_offset')
            ->get();
        
        if ($errors->isEmpty()) {
            $this->info("No failed batches found.");
            return 0;
        }
        
        $this->info("Found " . $errors->count() . " failed batches to retry.");
        $this->line('');
        
        $groupedByTable = $errors->groupBy('table_name');
        
        foreach ($groupedByTable as $table => $tableErrors) {
            $this->line("Retrying table: <fg=cyan>{$table}</> (" . $tableErrors->count() . " batches)");
            
            foreach ($tableErrors as $error) {
                $this->info("  └─ Retrying batch at offset {$error->batch_offset}...");
                
                // Retry this specific batch
                $columnsMeta = $this->discovery->getTableColumns($table);
                $columnsByName = [];
                $blobColumns = [];

                foreach ($columnsMeta as $columnMeta) {
                    $columnsByName[$columnMeta['COLUMN_NAME']] = $columnMeta;
                    if (in_array(strtoupper($columnMeta['DATA_TYPE']), ['BLOB', 'LONGBLOB', 'MEDIUMBLOB', 'TINYBLOB', 'BINARY', 'VARBINARY'])) {
                        $blobColumns[] = $columnMeta['COLUMN_NAME'];
                    }
                }
                
                $rows = DB::connection($sourceConnection)
                    ->table($table)
                    ->offset($error->batch_offset)
                    ->limit($error->batch_size)
                    ->get();
                
                if ($rows->isEmpty()) {
                    $this->warn("  └─ No data found at this offset, marking as resolved.");
                    $this->markBatchResolved($sourceConnection, $table, $error->batch_offset);
                    continue;
                }
                
                $data = $rows->map(function ($row) use ($columnsByName, $blobColumns, $table) {
                    $rowArray = (array) $row;
                    $mapped = [];

                    foreach ($rowArray as $key => $value) {
                        if (in_array($key, $blobColumns) && $value !== null) {
                            $mapped[$key] = DB::raw("decode('" . bin2hex($value) . "', 'hex')");
                        } else {
                            $mapped[$key] = $this->typeMapper->mapValue($value, $columnsByName[$key] ?? []);
                        }
                    }

                    return $mapped;
                })->toArray();
                
                try {
                    DB::connection($targetConnection)
                        ->table($table)
                        ->insert($data);
                    
                    $this->info("  └─ Success! Synced " . count($data) . " rows.");
                    $this->markBatchResolved($sourceConnection, $table, $error->batch_offset);
                    
                } catch (\Exception $e) {
                    $this->error("  └─ Still failing: " . $e->getMessage());
                    // Update error log with new error message
                    DB::connection($sourceConnection)
                        ->table('sync_error_log')
                        ->where('id', $error->id)
                        ->update([
                            'error_message' => substr($e->getMessage(), 0, 5000),
                            'error_at' => now(),
                        ]);
                }
            }
            
            $this->line('');
        }
        
        $this->info("Retry completed!");
        $this->showErrorSummary($sourceConnection);
        
        return 0;
    }
    
    /**
     * Drop all tables in target database
     */
    protected function dropAllTargetTables($targetConnection)
    {
        $this->warn('Dropping all tables in target database...');
        
        // Query all tables in public schema
        $tables = DB::connection($targetConnection)
            ->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        
        if (empty($tables)) {
            $this->info('No tables found in target database.');
            return;
        }
        
        $tableCount = count($tables);
        $this->line("Found {$tableCount} tables to drop.");
        
        // Drop each table with CASCADE
        DB::connection($targetConnection)->statement('SET session_replication_role = replica;');
        
        foreach ($tables as $table) {
            $tableName = $table->tablename;
            try {
                DB::connection($targetConnection)->statement("DROP TABLE IF EXISTS \"{$tableName}\" CASCADE");
                $this->line("  ✓ Dropped: {$tableName}");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed to drop {$tableName}: " . $e->getMessage());
            }
        }
        
        DB::connection($targetConnection)->statement('SET session_replication_role = DEFAULT;');
        
        $this->info("Dropped {$tableCount} tables from target database.");
        $this->line('');
    }
    
    protected function showErrorSummary($connection)
    {
        try {
            $errorCount = DB::connection($connection)
                ->table('sync_error_log')
                ->where('resolved', false)
                ->count();
            
            if ($errorCount > 0) {
                $this->line('');
                $this->warn("⚠ {$errorCount} batches still have errors.");
                $this->info("Run 'php artisan sync:initial --retry-errors' to retry failed batches.");
                $this->line('');
                
                // Show summary by table
                $summary = DB::connection($connection)
                    ->table('sync_error_log')
                    ->selectRaw('table_name, COUNT(*) as error_count')
                    ->where('resolved', false)
                    ->groupBy('table_name')
                    ->get();
                
                $this->table(['Table', 'Failed Batches'], $summary->map(function($row) {
                    return [$row->table_name, $row->error_count];
                })->toArray());
            }
        } catch (\Exception $e) {
            // Silently fail - error log table might not exist
        }
    }
    
    protected function initProgress($connection, $table, $totalRows, $batchSize, $resumeOffset)
    {
        try {
            $existing = DB::connection($connection)
                ->table('sync_progress')
                ->where('table_name', $table)
                ->first();
            
            if (!$existing) {
                DB::connection($connection)->table('sync_progress')->insert([
                    'table_name' => $table,
                    'status' => 'pending',
                    'total_rows' => $totalRows,
                    'synced_rows' => 0,
                    'failed_rows' => 0,
                    'last_synced_offset' => $resumeOffset,
                    'batch_size' => $batchSize,
                    'started_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::connection($connection)
                    ->table('sync_progress')
                    ->where('table_name', $table)
                    ->update([
                        'total_rows' => $totalRows,
                        'batch_size' => $batchSize,
                        'started_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        } catch (\Exception $e) {
            // Silently fail - progress table might not exist yet
        }
    }
    
    protected function updateProgress($connection, $table, $status, $totalRows, $syncedRows, $failedRows, $offset)
    {
        try {
            $updateData = [
                'status' => $status,
                'total_rows' => $totalRows,
                'synced_rows' => $syncedRows,
                'failed_rows' => $failedRows,
                'last_synced_offset' => $offset,
                'updated_at' => now(),
            ];
            
            if ($status === 'completed' || $status === 'failed') {
                $updateData['completed_at'] = now();
            }
            
            DB::connection($connection)
                ->table('sync_progress')
                ->where('table_name', $table)
                ->update($updateData);
        } catch (\Exception $e) {
            // Silently fail
        }
    }
    
    protected function getResumeOffset($connection, $table)
    {
        try {
            $progress = DB::connection($connection)
                ->table('sync_progress')
                ->where('table_name', $table)
                ->where('status', '!=', 'completed')
                ->first();
            
            if ($progress) {
                return $progress->last_synced_offset;
            }
        } catch (\Exception $e) {
            // Table might not exist
        }
        
        return 0;
    }
    
    protected function resetProgress($connection)
    {
        $table = $this->option('table');
        
        try {
            if ($table) {
                DB::connection($connection)
                    ->table('sync_progress')
                    ->where('table_name', $table)
                    ->delete();
                $this->info("Progress reset for table: {$table}");
            } else {
                DB::connection($connection)->table('sync_progress')->truncate();
                $this->info("All progress reset.");
            }
        } catch (\Exception $e) {
            $this->error("Failed to reset progress: " . $e->getMessage());
        }
        
        return 0;
    }
    
    protected function showProgressSummary($connection)
    {
        try {
            $summary = DB::connection($connection)
                ->table('sync_progress')
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get();
            
            if ($summary->isNotEmpty()) {
                $this->line('');
                $this->info('Sync Progress Summary:');
                $this->table(['Status', 'Tables'], $summary->map(function($row) {
                    return [ucfirst($row->status), $row->count];
                })->toArray());
                
                // Show incomplete tables
                $incomplete = DB::connection($connection)
                    ->table('sync_progress')
                    ->where('status', '!=', 'completed')
                    ->get();
                
                if ($incomplete->isNotEmpty()) {
                    $this->line('');
                    $this->warn('Incomplete tables:');
                    $this->table(
                        ['Table', 'Status', 'Progress', 'Last Offset'],
                        $incomplete->map(function($row) {
                            $progress = $row->total_rows > 0 
                                ? round(($row->synced_rows / $row->total_rows) * 100, 2) 
                                : 0;
                            return [
                                $row->table_name, 
                                $row->status, 
                                $progress . '%',
                                $row->last_synced_offset
                            ];
                        })->toArray()
                    );
                }
            }
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
