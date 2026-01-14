<?php

namespace MylabDatabaseSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MylabDatabaseSync\Services\TableDiscoveryService;

class SetupTriggersCommand extends Command
{
    protected $signature = 'sync:setup-triggers 
                            {--drop : Drop existing triggers before creating new ones}
                            {--table= : Setup trigger for specific table only}';

    protected $description = 'Setup database triggers for CDC sync';

    protected $discovery;

    public function __construct(TableDiscoveryService $discovery)
    {
        parent::__construct();
        $this->discovery = $discovery;
    }

    public function handle()
    {
        $connection = config('database-sync.source_connection');
        $auditTable = config('database-sync.audit_table');
        
        $tables = $this->option('table') 
            ? [$this->option('table')]
            : $this->discovery->getAllTables();

        $this->info("Setting up triggers for " . count($tables) . " tables...");
        
        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        foreach ($tables as $table) {
            if ($this->option('drop')) {
                $this->dropTriggers($connection, $table);
            }
            
            $this->createTriggers($connection, $table, $auditTable);
            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->info("Triggers setup completed!");

        return 0;
    }

    protected function dropTriggers($connection, $table)
    {
        $triggers = ['INSERT', 'UPDATE', 'DELETE'];
        
        foreach ($triggers as $operation) {
            $triggerName = "{$table}_after_{$operation}_trigger";
            
            try {
                DB::connection($connection)->statement("DROP TRIGGER IF EXISTS `{$triggerName}`");
            } catch (\Exception $e) {
                // Ignore if trigger doesn't exist
            }
        }
    }

    protected function createTriggers($connection, $table, $auditTable)
    {
        $columns = $this->discovery->getTableColumns($table);
        $primaryKeys = $this->discovery->getPrimaryKeys($table);
        
        // Build column list for JSON_OBJECT
        $columnsList = [];
        foreach ($columns as $column) {
            $colName = is_array($column) ? $column['COLUMN_NAME'] : $column->COLUMN_NAME;
            $columnsList[] = "'{$colName}', {TABLE_ALIAS}.`{$colName}`";
        }
        $columnsJson = implode(', ', $columnsList);
        
        // Build primary key identifier
        $pkIdentifier = [];
        if (!empty($primaryKeys)) {
            foreach ($primaryKeys as $pk) {
                $pkIdentifier[] = "'{$pk}:', COALESCE({TABLE_ALIAS}.`{$pk}`, 'NULL')";
            }
            $pkConcatenated = "CONCAT(" . implode(", ',', ", $pkIdentifier) . ")";
        } else {
            // No PK: use all columns
            $pkConcatenated = "NULL";
        }

        // Create INSERT trigger
        $this->createInsertTrigger($connection, $table, $auditTable, $columnsJson, $pkConcatenated);
        
        // Create UPDATE trigger
        $this->createUpdateTrigger($connection, $table, $auditTable, $columnsJson, $pkConcatenated);
        
        // Create DELETE trigger
        $this->createDeleteTrigger($connection, $table, $auditTable, $columnsJson, $pkConcatenated);
    }

    protected function createInsertTrigger($connection, $table, $auditTable, $columnsJson, $pkConcatenated)
    {
        $triggerName = "{$table}_after_INSERT_trigger";
        $columnsJsonNew = str_replace('{TABLE_ALIAS}', 'NEW', $columnsJson);
        $pkNew = str_replace('{TABLE_ALIAS}', 'NEW', $pkConcatenated);
        
        $sql = "
CREATE TRIGGER `{$triggerName}` 
AFTER INSERT ON `{$table}`
FOR EACH ROW
BEGIN
    INSERT INTO `{$auditTable}` (
        table_name, 
        record_id, 
        operation, 
        old_data, 
        new_data, 
        synced, 
        retry_count,
        created_at
    ) VALUES (
        '{$table}',
        {$pkNew},
        'INSERT',
        NULL,
        JSON_OBJECT({$columnsJsonNew}),
        FALSE,
        0,
        NOW()
    );
END
        ";

        DB::connection($connection)->statement($sql);
    }

    protected function createUpdateTrigger($connection, $table, $auditTable, $columnsJson, $pkConcatenated)
    {
        $triggerName = "{$table}_after_UPDATE_trigger";
        $columnsJsonOld = str_replace('{TABLE_ALIAS}', 'OLD', $columnsJson);
        $columnsJsonNew = str_replace('{TABLE_ALIAS}', 'NEW', $columnsJson);
        $pkOld = str_replace('{TABLE_ALIAS}', 'OLD', $pkConcatenated);
        
        $sql = "
CREATE TRIGGER `{$triggerName}` 
AFTER UPDATE ON `{$table}`
FOR EACH ROW
BEGIN
    INSERT INTO `{$auditTable}` (
        table_name, 
        record_id, 
        operation, 
        old_data, 
        new_data, 
        synced, 
        retry_count,
        created_at
    ) VALUES (
        '{$table}',
        {$pkOld},
        'UPDATE',
        JSON_OBJECT({$columnsJsonOld}),
        JSON_OBJECT({$columnsJsonNew}),
        FALSE,
        0,
        NOW()
    );
END
        ";

        DB::connection($connection)->statement($sql);
    }

    protected function createDeleteTrigger($connection, $table, $auditTable, $columnsJson, $pkConcatenated)
    {
        $triggerName = "{$table}_after_DELETE_trigger";
        $columnsJsonOld = str_replace('{TABLE_ALIAS}', 'OLD', $columnsJson);
        $pkOld = str_replace('{TABLE_ALIAS}', 'OLD', $pkConcatenated);
        
        $sql = "
CREATE TRIGGER `{$triggerName}` 
AFTER DELETE ON `{$table}`
FOR EACH ROW
BEGIN
    INSERT INTO `{$auditTable}` (
        table_name, 
        record_id, 
        operation, 
        old_data, 
        new_data, 
        synced, 
        retry_count,
        created_at
    ) VALUES (
        '{$table}',
        {$pkOld},
        'DELETE',
        JSON_OBJECT({$columnsJsonOld}),
        NULL,
        FALSE,
        0,
        NOW()
    );
END
        ";

        DB::connection($connection)->statement($sql);
    }
}
