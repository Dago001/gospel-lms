# Production readiness checklist

The system must **not** be called production-ready until every box below is ticked by a person with evidence. As of this commit, none of the external-service boxes are ticked.

## Accounts and credentials (owner supplies)
- [ ] Paystack live keys; webhook URL registered; `php artisan paystack:smoke` passes with test keys on staging first
- [ ] Paystack: one real ₦ payment end to end on staging (test mode) + one test refund; verify refund webhook handling
- [ ] Zoom S2S OAuth app + webhook secret; one scheduled class, registrant join, attendance sync verified
- [ ] Cloudflare Stream account: upload, processing webhook, signed playback, progress tracking verified with a real video
- [ ] S3-compatible private bucket (versioned) and mail provider (SPF/DKIM/DMARC set)
- [ ] `ACADEMY_*` details, legal pages reviewed by counsel, real support email monitored

## Infrastructure
- [ ] Staging environment mirroring production; docs/DEPLOYMENT.md followed end to end
- [ ] HTTPS, HSTS, PHP-FPM tuned, Redis, queue workers (Supervisor), scheduler cron, Admin → System all green
- [ ] Off-server backups; `gospel:backup-verify` passing; one full restore rehearsal into a fresh database
- [ ] Monitoring/alerting (uptime on `/up`, Sentry DSN, failed-job and scheduler-heartbeat alerts)

## Quality gates
- [ ] CI green on GitHub (backend, frontend, e2e)
- [ ] k6 load test on staging (`scripts/load/`): record environment + results; p95 targets met at 500 concurrent users or capacity plan agreed
- [ ] Independent security review / pen test; findings closed
- [ ] Manual accessibility audit; findings closed
- [ ] Data protection: privacy notice, DPO/contact, breach procedure, retention periods confirmed against the `retention:purge` job in `backend/routes/console.php` (currently e.g. 90 days for the notification delivery log)
- [ ] User-acceptance run by the academy: a lecturer builds a course, a student pays, studies, sits an exam and receives a certificate that verifies publicly
