<?php

namespace MylabDatabaseSync\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TableDiscoveryService
{
    protected $sourceConnection;
    protected $excludeTables;

    public function __construct()
    {
        $this->sourceConnection = config('database-sync.source_connection');
        $this->excludeTables = array_merge(
            config('database-sync.tables.exclude', []),
            [config('database-sync.audit_table')]
        );
    }

    /**
     * Get all tables from source database
     */
    public function getAllTables(): array
    {
        $database = config("database.connections.{$this->sourceConnection}.database");
        
        $tables = DB::connection($this->sourceConnection)
            ->select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$database]);
        
        $result = [];
        foreach ($tables as $table) {
            $tableName = $table->TABLE_NAME;
            
            if ($this->shouldInclude($tableName)) {
                $result[] = $tableName;
            }
        }
        
        return $result;
    }

    /**
     * Get columns for a table
     */
    public function getTableColumns(string $table): array
    {
        $database = config("database.connections.{$this->sourceConnection}.database");
        
        $columns = DB::connection($this->sourceConnection)
            ->select("
                SELECT 
                    COLUMN_NAME,
                    DATA_TYPE,
                    COLUMN_TYPE,
                    IS_NULLABLE,
                    COLUMN_DEFAULT,
                    EXTRA,
                    CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION,
                    NUMERIC_SCALE
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION
            ", [$database, $table]);
        
        return array_map(function($col) { return (array) $col; }, $columns);
    }

    /**
     * Get primary key columns for a table
     */
    public function getPrimaryKeys(string $table): array
    {
        $database = config("database.connections.{$this->sourceConnection}.database");
        
        $keys = DB::connection($this->sourceConnection)
            ->select("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? 
                  AND TABLE_NAME = ?
                  AND CONSTRAINT_NAME = 'PRIMARY'
                ORDER BY ORDINAL_POSITION
            ", [$database, $table]);
        
        return array_map(function($key) { return $key->COLUMN_NAME; }, $keys);
    }

    /**
     * Get foreign keys for a table
     */
    public function getForeignKeys(string $table): array
    {
        $database = config("database.connections.{$this->sourceConnection}.database");
        
        $fks = DB::connection($this->sourceConnection)
            ->select("
                SELECT 
                    CONSTRAINT_NAME,
                    COLUMN_NAME,
                    REFERENCED_TABLE_NAME,
                    REFERENCED_COLUMN_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? 
                  AND TABLE_NAME = ?
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            ", [$database, $table]);
        
        return array_map(function($fk) { return (array) $fk; }, $fks);
    }

    /**
     * Build dependency graph for tables
     */
    public function buildDependencyGraph(array $tables): array
    {
        $graph = [];
        
        foreach ($tables as $table) {
            $fks = $this->getForeignKeys($table);
            $graph[$table] = array_unique(array_map(function($fk) { return $fk['REFERENCED_TABLE_NAME']; }, $fks));
        }
        
        return $graph;
    }

    /**
     * Sort tables by dependency (topological sort)
     */
    public function sortByDependency(array $tables): array
    {
        $graph = $this->buildDependencyGraph($tables);
        $sorted = [];
        $visited = [];
        
        $visit = function($table) use (&$visit, &$sorted, &$visited, $graph) {
            if (isset($visited[$table])) {
                return;
            }
            
            $visited[$table] = true;
            
            if (isset($graph[$table])) {
                foreach ($graph[$table] as $dependency) {
                    if (in_array($dependency, array_keys($graph))) {
                        $visit($dependency);
                    }
                }
            }
            
            $sorted[] = $table;
        };
        
        foreach ($tables as $table) {
            $visit($table);
        }
        
        return $sorted;
    }

    /**
     * Check if table should be included
     */
    protected function shouldInclude(string $table): bool
    {
        $includePattern = config('database-sync.tables.include');
        
        if ($includePattern === '*') {
            return !in_array($table, $this->excludeTables);
        }
        
        // Support regex pattern
        if (preg_match("/$includePattern/", $table)) {
            return !in_array($table, $this->excludeTables);
        }
        
        return false;
    }
}
