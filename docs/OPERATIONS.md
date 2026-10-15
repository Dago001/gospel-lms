# Operations: backups, restore, monitoring, troubleshooting

## Backups

* `php artisan gospel:backup` writes a PostgreSQL custom-format dump (`pg_dump -Fc`) to `BACKUP_PATH` (default `storage/app/backups`), records its SHA-256 checksum and per-table row counts in `backup_audit_records`, optionally uploads to the private bucket (`BACKUP_UPLOAD=true`) and prunes after `BACKUP_KEEP_DAYS`. Scheduled nightly at 02:30 Africa/Lagos.
* `php artisan gospel:backup-verify` restores the newest dump into a **scratch database** (never the live one), compares row counts and checksum, and records the result. Scheduled weekly. A truncated, tampered or incomplete dump fails verification (covered by `tests/Backup/BackupRestoreTest`, which runs real `pg_dump`/`pg_restore`).
* Uploaded files (assignments, certificates, receipts, photos) live in the private S3 bucket: enable bucket versioning and a lifecycle policy there; the database dump does not contain them.
* Keep at least one copy off the server and in a different account/region. A backup that has never been restored is not a backup: review the "last verified" status in Admin → System.

### Restore into production (manual, destructive: needs a decision by the owner)

```bash
php artisan down
createdb gospel_lms_restored && pg_restore --no-owner --no-privileges -d gospel_lms_restored /path/to/dump.dump
# verify counts/spot-check in gospel_lms_restored, then swap DB_DATABASE in .env (or rename databases) and:
php artisan config:cache && php artisan queue:restart && php artisan up
```
Restore into a **new** database and switch over; do not restore over the live database.

## Monitoring

* `GET /up`: Laravel's built-in liveness probe for load balancers/uptime monitors (no secrets, no dependency checks).
* Admin → System (`GET /api/v1/admin/health`, needs `settings.manage`): DB, cache, queue, storage and scheduler-heartbeat checks.
* Admin → Integrations: which providers are configured and their last-success times.
* Logs: `storage/logs/laravel.log` (set `LOG_CHANNEL=daily`), worker log, Nginx and PHP-FPM logs. Errors are never swallowed; unknown payment outcomes freeze the order for finance review and appear in Admin → Finance → Exceptions.
* Set `SENTRY_LARAVEL_DSN` for error aggregation (no-op when empty). Alert on: failed jobs, stale scheduler heartbeat, payment exceptions, webhook signature failures, backup-verify failure, disk usage.

## Troubleshooting

| Symptom | Likely cause / check |
|---|---|
| Login works in curl but SPA shows signed out, or "Session store not set" | Origin not in `SANCTUM_STATEFUL_DOMAINS`, or SPA and API on different hosts. Same-origin is required. |
| `419` on every POST | `/sanctum/csrf-cookie` not reachable through the proxy, or cookie `Secure` over plain HTTP. |
| Verification / reset emails never arrive | `MAIL_*` wrong; check the worker log and the notification delivery log in Admin. |
| Payment paid at Paystack but no access | Check Admin → Finance → Webhooks and Exceptions; `php artisan payments:reconcile` re-verifies pending payments. Never grant access by hand without a verified payment. |
| Video will not play | Provider not configured (Admin → Integrations), asset not `ready`, or signing key mismatch. See `docs/VIDEO.md`. |
| Exams not auto-submitting / reminders not sent | Scheduler not running (stale heartbeat) or workers stopped. |
| `pg_dump` not found | Set `PG_DUMP_BIN` / `PG_RESTORE_BIN` and install `postgresql-client-16`. |
