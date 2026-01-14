# MyLab Database Sync

Laravel package untuk sinkronisasi database MySQL/MariaDB ke PostgreSQL menggunakan SQL Triggers. Solusi CDC (Change Data Capture) yang ringan dan production-ready tanpa dependency eksternal seperti Kafka atau Debezium.

## Features

✅ **Trigger-Based CDC** - Capture semua perubahan data (INSERT/UPDATE/DELETE) via SQL triggers  
✅ **Auto Schema Discovery** - Deteksi otomatis 381+ tables dengan dependency ordering  
✅ **Type Mapping** - Konversi otomatis MySQL types ke PostgreSQL (ENUM, JSON, BLOB, dll)  
✅ **NULL Byte Cleaning** - Handle data corruption (0x00 bytes) otomatis  
✅ **Batch Processing** - Sync data dalam batch untuk performa optimal  
✅ **Error Recovery** - Row-by-row fallback dengan max 3 retry + exponential backoff  
✅ **Progress Tracking** - Resume capability dari offset terakhir jika interrupted  
✅ **Graceful Shutdown** - Handle SIGTERM/SIGINT tanpa corrupt data  
✅ **Data Sanitization** - Auto-fix invalid values (date, numeric, string) dengan safe defaults  
✅ **Error Logging** - Track failed batches dengan detail PK dan sample data  
✅ **Queue Support** - Async processing via Laravel Queue (optional)  
✅ **Monitoring** - Real-time stats dan logging  
✅ **Zero Code Changes** - Tidak perlu ubah controller/model, capture raw SQL queries  

## Requirements

- PHP 7.2 atau lebih tinggi
- Laravel 5.8 atau lebih tinggi
- MySQL/MariaDB 5.7+ (source database)
- PostgreSQL 10+ (target database)

## Installation

### 1. Install via Composer

```bash
composer require mylab/database-sync
```

Atau tambahkan di `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../mylab-database-sync"
        }
    ],
    "require": {
        "mylab/database-sync": "*"
    }
}
```

Kemudian:

```bash
composer update mylab/database-sync
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="MylabDatabaseSync\DatabaseSyncServiceProvider"
```

Ini akan membuat file:
- `config/database-sync.php` - Konfigurasi utama
- `database/migrations/2026_01_15_000001_create_sync_audit_log_table.php` - Migration audit log

### 3. Setup Database Connections

Edit `.env`:

```env
# Source Database (MySQL/MariaDB)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mylab
DB_USERNAME=root
DB_PASSWORD=

# Target Database (PostgreSQL)
PGSQL_CONNECTION=pgsql
PGSQL_HOST=127.0.0.1
PGSQL_PORT=5432
PGSQL_DATABASE=mylab
PGSQL_USERNAME=postgres
PGSQL_PASSWORD=

# Sync Configuration
SYNC_SOURCE_CONNECTION=mysql
SYNC_TARGET_CONNECTION=pgsql
SYNC_BATCH_SIZE=100
SYNC_INTERVAL=5
SYNC_USE_QUEUE=false
```

Edit `config/database.php`, pastikan ada koneksi `pgsql`:

```php
'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('PGSQL_HOST', '127.0.0.1'),
    'port' => env('PGSQL_PORT', '5432'),
    'database' => env('PGSQL_DATABASE', 'forge'),
    'username' => env('PGSQL_USERNAME', 'forge'),
    'password' => env('PGSQL_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'schema' => 'public',
    'sslmode' => 'prefer',
],
```

### 4. Run Migration

Buat audit log table di source database (MySQL):

```bash
php artisan migrate --database=mysql
```

## Usage

### Step 1: Initial Full Sync

Sync semua data existing dari MySQL ke PostgreSQL:

```bash
# Sync semua tables dengan auto-create
php artisan sync:initial --create-tables

# Atau sync table tertentu saja
php artisan sync:initial --table=pasien --create-tables

# Custom batch size
php artisan sync:initial --create-tables --batch=500
```

**Output:**
```
Starting initial sync for 381 tables...

Syncing table: pasien
  └─ Creating table in target database...
  └─ Table created successfully
  └─ Total rows: 48523
  48523/48523 [============================] 100%
  └─ Synced 48523 rows

Syncing table: result
  └─ Total rows: 69842
  69842/69842 [============================] 100%
  └─ Synced 69842 rows

Initial sync completed!
```

### Step 2: Setup Triggers

Generate SQL triggers untuk capture perubahan data:

```bash
# Setup triggers untuk semua tables
php artisan sync:setup-triggers

# Drop existing triggers dulu (jika ada)
php artisan sync:setup-triggers --drop

# Setup trigger untuk table tertentu
php artisan sync:setup-triggers --table=pasien
```

**Output:**
```
Setting up triggers for 381 tables...
381/381 [============================] 100%
Triggers setup completed!
```

### Step 3: Run Sync Worker

Jalankan worker untuk sync perubahan real-time:

```bash
# Daemon mode (continuous sync)
php artisan sync:database --daemon

# One-time sync (process current batch only)
php artisan sync:database --once
```

**Daemon Output:**
```
Starting daemon mode (interval: 5s)...
Press Ctrl+C to stop

[14:23:45] Iteration: 1 | Processed: 15 | Success: 15 | Failed: 0 | Pending: 0 | Time: 234.56ms
[14:23:50] Iteration: 2 | Processed: 8 | Success: 8 | Failed: 0 | Pending: 0 | Time: 125.34ms
```

### Step 4: Schedule Cleanup (Optional)

Tambahkan di `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Cleanup audit log setiap hari jam 2 pagi
    $schedule->command('sync:cleanup')->dailyAt('02:00');
}
```

Atau manual:

```bash
# Cleanup records older than 7 days
php artisan sync:cleanup

# Custom retention
php artisan sync:cleanup --days=14

# Dry run (preview only)
php artisan sync:cleanup --dry-run
```

---

## Post-Installation Setup

Setelah install package via composer, ikuti langkah-langkah berikut untuk setup sync system:

### Step 1: Publish Configuration & Run Migration

```bash
# Publish config dan migration files
php artisan vendor:publish --provider="MylabDatabaseSync\DatabaseSyncServiceProvider"

# Run migration untuk create audit log table (di MySQL/source database)
php artisan migrate --database=mysql
```

**Expected output:**
```
Migrated: 2026_01_15_000001_create_sync_audit_log_table
Migrated: 2026_01_15_000002_create_sync_error_log_table
```

### Step 2: Configure Database Connections

Edit `.env` file:

```bash
# Source Database (MySQL)
SYNC_SOURCE_CONNECTION=mysql
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mylab
DB_USERNAME=root
DB_PASSWORD=secret

# Target Database (PostgreSQL)
SYNC_TARGET_CONNECTION=pgsql
PGSQL_HOST=127.0.0.1
PGSQL_PORT=5432
PGSQL_DATABASE=mylab_analytics
PGSQL_USERNAME=postgres
PGSQL_PASSWORD=secret

# Sync Settings
SYNC_BATCH_SIZE=100
SYNC_USE_QUEUE=false
```

**⚠️ Important:** Set `SYNC_USE_QUEUE=false` untuk direct sync (tanpa job queue). Jika `true`, pastikan queue worker sudah running.

### Step 3: Setup Triggers di MySQL

```bash
# Generate triggers untuk capture perubahan data
php artisan sync:setup-triggers

# ATAU, jika sudah ada triggers lama (drop dulu)
php artisan sync:setup-triggers --drop
```

**Expected output:**
```
Setting up triggers for 389 tables...
389/389 [============================] 100%
Triggers setup completed!
Total triggers created: 1167 (389 tables × 3 operations)
```

**What it does:** Creates INSERT, UPDATE, DELETE triggers di semua tables untuk auto-capture changes ke `sync_audit_log` table.

### Step 4: Run Initial Full Sync

```bash
# Sync semua existing data dari MySQL ke PostgreSQL
php artisan sync:initial --create-tables

# Monitor progress
tail -f storage/logs/sync.log
```

**Expected output:**
```
Starting initial sync for 389 tables...

Syncing table: pasien
  └─ Creating table in target database...
  └─ Table created successfully
  └─ Total rows: 48,523
  48523/48523 [============================] 100%
  └─ Synced 48,523 rows in 12.5s

...

Initial sync completed!
Total tables: 389
Total rows synced: 2,847,563
Total time: 45m 23s
```

### Step 5: Setup Automated Incremental Sync

Pilih salah satu metode automation:

#### Option A: Cron Job (Recommended untuk production)

```bash
# Edit crontab
crontab -e

# Add this line (runs every 1 minute)
* * * * * cd /var/www/mylab && /usr/bin/php7.2 artisan sync:database >> /var/www/mylab/storage/logs/sync.log 2>&1
```

**Verify cron running:**
```bash
# Check cron status
crontab -l

# Monitor sync log
tail -f storage/logs/sync.log

# Check pending records
php artisan sync:database --check-pending
```

#### Option B: Supervisor (Alternative - daemon mode)

Create `/etc/supervisor/conf.d/mylab-sync.conf`:

```ini
[program:mylab-sync]
process_name=%(program_name)s
command=php /var/www/mylab/artisan sync:database --daemon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/mylab/storage/logs/sync.log
```

Start supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mylab-sync
```

### Step 6: Verify Sync is Working

```bash
# Check pending records in audit log
php artisan sync:database --check-pending

# Manual sync (test run)
php artisan sync:database

# Check PostgreSQL data
psql -h 127.0.0.1 -p 15432 -U tiara -d mylab -c "SELECT COUNT(*) FROM pasien;"

# Compare row counts
php artisan sync:verify-counts
```

### Step 7: Monitor Sync Status

```bash
# View sync log
tail -f storage/logs/sync.log

# Check error log
php artisan sync:show-errors

# View recent synced records
mysql -e "SELECT * FROM sync_audit_log ORDER BY id DESC LIMIT 10;"

# Check trigger status
mysql -e "SHOW TRIGGERS LIKE '%pasien%';"
```

### Common Commands Reference

```bash
# Check pending records
php artisan sync:database --check-pending

# Manual sync (one-time run)
php artisan sync:database

# Daemon mode (continuous sync, 5 second interval)
php artisan sync:database --daemon

# Sync specific table only
php artisan sync:database --table=pasien

# Re-sync failed records
php artisan sync:retry-failed

# View error log
php artisan sync:show-errors

# Cleanup old audit log (keep last 7 days)
php artisan sync:cleanup

# Verify row counts between source and target
php artisan sync:verify-counts

# Drop and recreate triggers
php artisan sync:setup-triggers --drop
```

### Troubleshooting

#### 1. Cron not syncing?

```bash
# Check cron is running
ps aux | grep cron

# Check cron logs
grep CRON /var/log/syslog

# Check sync log
tail -f storage/logs/sync.log

# Verify pending records exist
mysql -e "SELECT COUNT(*) FROM sync_audit_log WHERE synced = 0;"
```

#### 2. Data not syncing to PostgreSQL?

```bash
# Check use_queue setting (should be false for direct sync)
grep SYNC_USE_QUEUE .env

# Check database connections
php artisan tinker
>>> DB::connection('mysql')->getPdo();
>>> DB::connection('pgsql')->getPdo();

# Manual test sync
php artisan sync:database --verbose
```

#### 3. Trigger not firing?

```bash
# Check triggers exist
mysql -e "SHOW TRIGGERS;"

# Test manual insert
mysql -e "INSERT INTO pasien (nama, tgl_lahir) VALUES ('Test', '2000-01-01');"
mysql -e "SELECT * FROM sync_audit_log ORDER BY id DESC LIMIT 1;"

# Re-create triggers
php artisan sync:setup-triggers --drop
```

#### 4. Sync delay too long?

```bash
# Reduce cron interval (every 30 seconds)
*/30 * * * * cd /var/www/mylab && php artisan sync:database >> storage/logs/sync.log 2>&1

# OR increase batch size in config
# Edit config/database-sync.php
'batch_size' => 500, // default 100

# OR use daemon mode instead
php artisan sync:database --daemon
```

---

## Configuration

Edit `config/database-sync.php`:

### Connection Settings

```php
'source_connection' => env('SYNC_SOURCE_CONNECTION', 'mysql'),
'target_connection' => env('SYNC_TARGET_CONNECTION', 'pgsql'),
'audit_table' => 'sync_audit_log',
```

### Performance Tuning

```php
'batch_size' => env('SYNC_BATCH_SIZE', 100),
'sync_interval' => env('SYNC_INTERVAL', 5), // seconds

'performance' => [
    'use_queue' => env('SYNC_USE_QUEUE', true),
    'queue_name' => 'database-sync',
    'memory_limit' => 256, // MB
],
```

### Table Filtering

```php
'tables' => [
    'include' => ['*'],
    'exclude' => [
        'migrations',
        'jobs',
        'failed_jobs',
        'password_resets',
    ],
],
```

### Error Handling

Package menggunakan multi-layer error handling strategy:

#### 1. Batch Insert Error → Row-by-Row Fallback
Jika batch insert gagal (misalnya: parameter limit, constraint violation):
- Automatic fallback ke row-by-row insert dengan sanitization
- Tidak berhenti total, tetap lanjut sync row berikutnya
- Log error ke `sync_error_log` table dengan detail PK dan sample data

#### 2. Row Insert Error → Max 3 Retry dengan Exponential Backoff
Jika row insert gagal:
- Retry 1: wait 0.1s → retry
- Retry 2: wait 0.2s → retry  
- Retry 3: wait 0.4s → retry
- After 3 retries: log error dan skip row (tidak block table sync)

#### 3. Graceful Shutdown (SIGTERM/SIGINT)
Jika proses di-interrupt (Ctrl+C, kill, timeout):
- Finish current batch sebelum stop
- Save progress (offset, synced count, failed count) ke `sync_progress` table
- Resume dari offset terakhir dengan `--resume` option

#### 4. No Timeout Internal
- Tidak ada timeout internal di command (bisa sync table besar tanpa limit)
- Jika perlu timeout, gunakan external timeout: `timeout 3600 php artisan sync:initial`
- Jika timeout eksternal terjadi: progress saved, resume dengan `--resume`

```php
'retry' => [
    'max_attempts' => 3,
    'delay' => 1000, // milliseconds
],
```

**Resume After Interruption:**
```bash
# Cek table yang belum selesai
php artisan sync:initial --show-progress

# Resume sync dari offset terakhir
php artisan sync:initial --resume

# Retry batch yang failed
php artisan sync:initial --retry-errors
```

### Monitoring

```php
'monitoring' => [
    'enabled' => true,
    'log_channel' => 'daily',
],
```

## Production Deployment

### Using Supervisor

Create `/etc/supervisor/conf.d/mylab-sync.conf`:

```ini
[program:mylab-sync]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mylab/artisan sync:database --daemon
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/mylab/storage/logs/sync-worker.log
```

Reload supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mylab-sync:*
```

### Using systemd

Create `/etc/systemd/system/mylab-sync.service`:

```ini
[Unit]
Description=MyLab Database Sync Worker
After=network.target mysql.service postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/mylab
ExecStart=/usr/bin/php artisan sync:database --daemon
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable mylab-sync
sudo systemctl start mylab-sync
sudo systemctl status mylab-sync
```

## Troubleshooting

### Check Sync Status

```bash
# Via command
php artisan sync:database --once

# Via tinker
php artisan tinker
>>> app(\MylabDatabaseSync\Services\DatabaseSyncService::class)->getStats();
=> [
     "total" => 15234,
     "synced" => 15200,
     "pending" => 34,
     "failed" => 0,
   ]
```

### View Audit Log

```sql
-- Check pending records
SELECT * FROM sync_audit_log 
WHERE synced = FALSE 
ORDER BY created_at DESC 
LIMIT 10;

-- Check failed records
SELECT * FROM sync_audit_log 
WHERE synced = FALSE 
AND retry_count >= 3
ORDER BY created_at DESC;

-- Check error messages
SELECT table_name, operation, error_message, COUNT(*) as count
FROM sync_audit_log
WHERE error_message IS NOT NULL
GROUP BY table_name, operation, error_message
ORDER BY count DESC;
```

### Common Issues

**1. NULL Byte Errors (0x00)**

Package automatically cleans NULL bytes in `TypeMapperService`. Jika masih ada error, cek data source:

```sql
-- Find NULL bytes in specific column
SELECT * FROM pasien WHERE telepon LIKE '%\0%';

-- Update to remove NULL bytes
UPDATE pasien SET telepon = REPLACE(telepon, '\0', '') WHERE telepon LIKE '%\0%';
```

**2. Primary Key Conflicts**

Pastikan semua tables punya primary key yang jelas:

```sql
-- Check tables without PK
SELECT TABLE_NAME 
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'mylab' 
AND TABLE_TYPE = 'BASE TABLE'
AND TABLE_NAME NOT IN (
    SELECT DISTINCT TABLE_NAME 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE CONSTRAINT_NAME = 'PRIMARY'
);
```

**3. Memory Limit**

Jika sync worker kehabisan memory:

```php
// config/database-sync.php
'performance' => [
    'memory_limit' => 512, // Increase to 512MB
],
```

Atau:

```bash
php -d memory_limit=512M artisan sync:database --daemon
```

**4. Trigger Overhead**

Untuk high-traffic tables, consider:

```php
// config/database-sync.php
'tables' => [
    'exclude' => [
        'sessions',      // Very high write frequency
        'cache',         // Temporary data
        'log_activity',  // Not critical for replication
    ],
],
```

## Performance Benchmarks

Based on testing with MyLab production database (381 tables, 48K+ pasien, 69K+ result):

- **Initial Sync**: 381 tables, ~150K rows → 5-10 minutes
- **Trigger Overhead**: ~5-10% write performance impact
- **Sync Worker**: Processes 100 records/second average
- **Memory Usage**: ~80-120MB for daemon mode
- **Lag Time**: <1 second for normal traffic (<1000 writes/sec)

## License

MIT License - see LICENSE file for details

## Support

Untuk bug reports atau feature requests, contact MyLab Development Team.
