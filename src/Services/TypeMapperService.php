<?php

namespace MylabDatabaseSync\Services;

class TypeMapperService
{
    /**
     * Map MySQL/MariaDB type to PostgreSQL type
     */
    public function mapType(array $column): string
    {
        $dataType = strtolower($column['DATA_TYPE'] ?? '');
        $columnType = strtolower($column['COLUMN_TYPE'] ?? '');
        $isAutoIncrement = stripos($column['EXTRA'] ?? '', 'auto_increment') !== false;

        if ($isAutoIncrement) {
            if ($dataType === 'bigint') {
                return 'BIGSERIAL';
            }

            if (in_array($dataType, ['tinyint', 'smallint'], true)) {
                return 'SMALLSERIAL';
            }

            return 'SERIAL';
        }
        
        // Handle specific types first
        if (strpos($columnType, 'tinyint(1)') !== false) {
            return 'BOOLEAN';
        }
        
        // Map by data type using switch
        switch($dataType) {
            case 'tinyint':
            case 'smallint':
                return 'SMALLINT';
            case 'mediumint':
            case 'int':
            case 'integer':
                return 'INTEGER';
            case 'bigint':
                return 'BIGINT';
            case 'float':
                return 'REAL';
            case 'double':
            case 'decimal':
            case 'numeric':
                return $this->mapDecimal($column);
            case 'char':
                return "CHAR({$column['CHARACTER_MAXIMUM_LENGTH']})";
            case 'varchar':
                return $this->mapVarchar($column);
            case 'text':
            case 'tinytext':
            case 'mediumtext':
            case 'longtext':
                return 'TEXT';
            case 'blob':
            case 'tinyblob':
            case 'mediumblob':
            case 'longblob':
                return 'BYTEA';
            case 'date':
                return 'DATE';
            case 'datetime':
            case 'timestamp':
                return 'TIMESTAMP';
            case 'time':
                return 'TIME';
            case 'year':
                return 'SMALLINT';
            case 'enum':
                return $this->mapEnum($column);
            case 'set':
                return 'TEXT'; // Store as comma-separated
            case 'json':
                return 'JSONB';
            case 'binary':
            case 'varbinary':
                return 'BYTEA';
            default:
                return 'TEXT';
        }
    }

    /**
     * Map decimal/numeric types
     */
    protected function mapDecimal(array $column): string
    {
        $precision = $column['NUMERIC_PRECISION'] ?? 10;
        $scale = $column['NUMERIC_SCALE'] ?? 0;
        
        return "NUMERIC({$precision},{$scale})";
    }

    /**
     * Map varchar types
     */
    protected function mapVarchar(array $column): string
    {
        $length = $column['CHARACTER_MAXIMUM_LENGTH'] ?? 255;
        
        // PostgreSQL max varchar is 10485760, but use TEXT for very long
        if ($length > 10000) {
            return 'TEXT';
        }
        
        return "VARCHAR({$length})";
    }

    /**
     * Map enum types (convert to CHECK constraint)
     */
    protected function mapEnum(array $column): string
    {
        // Extract enum values from COLUMN_TYPE
        // e.g., "enum('value1','value2','value3')"
        preg_match("/enum\((.*)\)/i", $column['COLUMN_TYPE'], $matches);
        
        if (!empty($matches[1])) {
            // For PostgreSQL, we'll use VARCHAR with CHECK constraint
            // The constraint will be added separately
            return 'VARCHAR(50)';
        }
        
        return 'VARCHAR(50)';
    }

    /**
     * Get enum values from column type
     */
    public function getEnumValues(string $columnType): array
    {
        preg_match("/enum\((.*)\)/i", $columnType, $matches);
        
        if (empty($matches[1])) {
            return [];
        }
        
        // Split by comma, remove quotes
        $values = explode(',', $matches[1]);
        return array_map(function($v) { return trim($v, "' "); }, $values);
    }

    /**
     * Map MySQL value to PostgreSQL value
     */
    public function mapValue($value, array $column = [])
    {
        if ($value === null) {
            return null;
        }
        
        $dataType = strtolower($column['DATA_TYPE'] ?? '');
        $columnType = strtolower($column['COLUMN_TYPE'] ?? '');
        
        // Handle boolean
        if (strpos($columnType, 'tinyint(1)') !== false) {
            return (bool) $value;
        }
        
        // Handle numeric types
        if (in_array($dataType, ['int', 'integer', 'bigint', 'smallint', 'mediumint'])) {
            return (int) $value;
        }
        
        if (in_array($dataType, ['float', 'double', 'decimal', 'numeric'])) {
            return (float) $value;
        }
        
        // Handle date/time
        if (in_array($dataType, ['date', 'datetime', 'timestamp'])) {
            if ($value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
                return null;
            }
        }
        
        // Handle JSON
        if ($dataType === 'json') {
            return is_string($value) ? $value : json_encode($value);
        }
        
        // Handle binary/blob
        if (in_array($dataType, ['blob', 'binary', 'varbinary'])) {
            return $value; // Laravel will handle bytea conversion
        }
        
        // Clean NULL bytes from strings
        if (is_string($value)) {
            return str_replace("\0", '', $value);
        }
        
        return $value;
    }

    /**
     * Sanitize value with fallback to valid default
     * This method tries to fix invalid values that would cause constraint violations
     */
    public function sanitizeValue($value, array $column = [], $tableName = null)
    {
        $originalValue = $value;
        
        $dataType = strtolower($column['DATA_TYPE'] ?? '');
        $columnType = strtolower($column['COLUMN_TYPE'] ?? '');
        $isNullable = $this->isNullable($column) || $this->shouldForceNullable($column);
        
        // If null and nullable, allow it
        if ($value === null && $isNullable) {
            return null;
        }
        
        // If null but NOT nullable, use safe default
        if ($value === null && !$isNullable) {
            return $this->getSafeDefault($column);
        }
        
        // Validate and fix date/time values
        if (in_array($dataType, ['date', 'datetime', 'timestamp'])) {
            if ($value === '0000-00-00' || $value === '0000-00-00 00:00:00' || empty($value)) {
                return $isNullable ? null : $this->getSafeDefault($column);
            }
            
            // Validate date format (allow dates before 1970, negative timestamps are valid)
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return $isNullable ? null : $this->getSafeDefault($column);
            }
        }
        
        // Validate numeric ranges
        if (in_array($dataType, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint'])) {
            $intValue = filter_var($value, FILTER_VALIDATE_INT);
            if ($intValue === false) {
                return $isNullable ? null : 0;
            }
            
            // Check range for smallint
            if ($dataType === 'smallint' || $dataType === 'tinyint') {
                if ($intValue < -32768 || $intValue > 32767) {
                    return $isNullable ? null : 0;
                }
            }
        }
        
        // Validate float/decimal
        if (in_array($dataType, ['float', 'double', 'decimal', 'numeric'])) {
            $floatValue = filter_var($value, FILTER_VALIDATE_FLOAT);
            if ($floatValue === false) {
                return $isNullable ? null : 0.0;
            }
        }
        
        // Validate string length for varchar/char
        if (in_array($dataType, ['varchar', 'char'])) {
            $maxLength = $column['CHARACTER_MAXIMUM_LENGTH'] ?? 255;
            if (strlen($value) > $maxLength) {
                return substr($value, 0, $maxLength);
            }
        }
        
        // Validate enum values
        if ($dataType === 'enum') {
            $enumValues = $this->getEnumValues($columnType);
            if (!empty($enumValues) && !in_array($value, $enumValues)) {
                return $isNullable ? null : ($enumValues[0] ?? '');
            }
        }
        
        // Clean invalid characters from strings
        if (is_string($value)) {
            // Remove NULL bytes
            $value = str_replace("\0", '', $value);
            
            // Remove invalid UTF-8 sequences
            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        }
        
        // Apply standard mapping
        $finalValue = $this->mapValue($value, $column);
        
        // Log if value was sanitized
        if ($originalValue !== $finalValue && $tableName) {
            $columnName = $column['COLUMN_NAME'] ?? 'unknown';
            $dataType = strtolower($column['DATA_TYPE'] ?? 'unknown');
            
            \Log::info("[SANITIZE] {$tableName}.{$columnName}", [
                'type' => $dataType,
                'original' => is_string($originalValue) && strlen($originalValue) > 100 
                    ? substr($originalValue, 0, 100) . '...' 
                    : $originalValue,
                'sanitized' => is_string($finalValue) && strlen($finalValue) > 100 
                    ? substr($finalValue, 0, 100) . '...' 
                    : $finalValue
            ]);
        }
        
        return $finalValue;
    }
    
    /**
     * Get safe default value for a column type
     */
    protected function getSafeDefault(array $column)
    {
        $dataType = strtolower($column['DATA_TYPE'] ?? '');
        $columnType = strtolower($column['COLUMN_TYPE'] ?? '');
        
        // Check if column has explicit default
        $default = $this->getDefault($column);
        if ($default !== null && $default !== 'NULL') {
            // Parse the default value
            $trimmed = trim($default, "'");
            
            if ($dataType === 'date') {
                return '1970-01-01';
            }
            if (in_array($dataType, ['datetime', 'timestamp'])) {
                return '1970-01-01 00:00:00';
            }
            
            return $trimmed;
        }
        
        // Type-specific safe defaults
        if (strpos($columnType, 'tinyint(1)') !== false) {
            return false;
        }
        
        if (in_array($dataType, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint'])) {
            return 0;
        }
        
        if (in_array($dataType, ['float', 'double', 'decimal', 'numeric'])) {
            return 0.0;
        }
        
        if ($dataType === 'date') {
            return '1970-01-01';
        }
        
        if (in_array($dataType, ['datetime', 'timestamp'])) {
            return '1970-01-01 00:00:00';
        }
        
        if ($dataType === 'time') {
            return '00:00:00';
        }
        
        if ($dataType === 'enum') {
            $enumValues = $this->getEnumValues($columnType);
            return $enumValues[0] ?? '';
        }
        
        if (in_array($dataType, ['text', 'varchar', 'char'])) {
            return '';
        }
        
        if (in_array($dataType, ['json', 'jsonb'])) {
            return '{}';
        }
        
        return '';
    }

    /**
     * Force nullable when source marks NOT NULL but default is null/invalid
     */
    public function shouldForceNullable(array $column): bool
    {
        $isNullable = strtoupper($column['IS_NULLABLE'] ?? '') === 'YES';
        if ($isNullable) {
            return false;
        }

        $defaultExists = array_key_exists('COLUMN_DEFAULT', $column);
        $rawDefault = $defaultExists ? (string) $column['COLUMN_DEFAULT'] : null;
        $normalizedDefault = $rawDefault !== null ? trim($rawDefault, "'\"") : null;

        $isZeroDate = in_array($normalizedDefault, ['0000-00-00', '0000-00-00 00:00:00'], true);
        $isDateLike = in_array(strtolower($column['DATA_TYPE'] ?? ''), ['date', 'datetime', 'timestamp', 'time'], true);

        if ($normalizedDefault === null || $normalizedDefault === '' || $isZeroDate) {
            return true;
        }

        if ($isDateLike && $isZeroDate) {
            return true;
        }

        return false;
    }

    /**
     * Check if column is nullable
     */
    public function isNullable(array $column): bool
    {
        return strtoupper($column['IS_NULLABLE'] ?? '') === 'YES';
    }

    /**
     * Get default value
     */
    public function getDefault(array $column): ?string
    {
        $default = array_key_exists('COLUMN_DEFAULT', $column) ? $column['COLUMN_DEFAULT'] : null;

        if ($default === null || strtoupper((string) $default) === 'NULL') {
            return null;
        }

        $rawDefault = (string) $default;
        $normalized = trim($rawDefault, "'\"");

        // Boolean defaults (tinyint(1) → boolean)
        $isBool = strpos(strtolower($column['COLUMN_TYPE'] ?? ''), 'tinyint(1)') !== false;
        if ($isBool && ($normalized === '0' || $normalized === '1')) {
            return $normalized === '1' ? 'true' : 'false';
        }

        if ($normalized === '0000-00-00' || $normalized === '0000-00-00 00:00:00') {
            return null;
        }

        if (stripos($rawDefault, 'CURRENT_TIMESTAMP') !== false) {
            return 'CURRENT_TIMESTAMP';
        }

        if (is_numeric($normalized)) {
            return (string) $normalized;
        }

        $escaped = str_replace("'", "''", $normalized);

        return "'{$escaped}'";
    }
}
