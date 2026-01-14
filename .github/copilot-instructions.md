# Copilot Instructions: mylab-database-sync

## Scope & Goal
- Package to sync MySQL (OLTP) to PostgreSQL (analytics/replica) via Laravel command/worker.
- Primary direction: mysql -> pgsql. Future: pgsql -> mysql is allowed via config; no bidirectional conflicts unless explicitly enabled.

## Hard Rules
- Never write directly from analytics app to PostgreSQL; sync is one-way unless config `direction` says otherwise.
- Do not rely on MySQL binlog; use trigger + `sync_audit_log` table.
- Idempotent operations only: insertOrIgnore/update/delete by primary key.
- No destructive schema changes unless requested.

## Components (expected)
- `config/sync.php`: direction, tables, batch size, retry, connections.
- `src/Services/DatabaseSyncService.php`: core one-way sync.
- `src/Commands/SyncDatabaseCommand.php`: scheduled runner.
- `src/Commands/SetupTriggersCommand.php`: generate triggers on source DB.
- `database/migrations/create_sync_audit_log.php`: audit table on source.
- `database/triggers/*.sql`: trigger templates per table.

## Workflow
1) Initial full copy MySQL -> PostgreSQL until row counts/checksums match.
2) Enable triggers on MySQL to log CRUD into `sync_audit_log`.
3) Scheduler/queue runs `sync:database` to move deltas to PostgreSQL.
4) If backlog/errors: retry; use idempotent ops.

### Modes to handle data health
- **Audit/Validation mode**: compare source vs target (row counts + checksum per PK range). If mismatch found, log details and do not overwrite automatically unless run with `--repair`.
- **Repair missing data mode**: optional `sync:database --full` or `--repair-missing` to re-seed rows that are absent or outdated on target (safe for “pincang” states). Still idempotent (insertOrIgnore/update by PK) and scoped by table/batch.
- **Realtime/delta mode**: default. Consume `sync_audit_log` (trigger-fed) and push to target continuously or on schedule.

## Safety & Performance
- Wrap writes to target in transactions; keep batch size configurable.
- Index audit table on (synced, created_at) and (table_name, record_id).
- Log errors with message + retry_count; no silent drops.

## Coding Standards
- PHP 7.2 compatible; Laravel 5.x+ compatible.
- ASCII only; concise comments only when needed.
- Keep package small and DRY; prefer config over hardcode.
