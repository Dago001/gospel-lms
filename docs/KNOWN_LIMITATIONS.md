# Known limitations and unverified areas

1. **No real external service has been called.** Paystack, Zoom and Cloudflare Stream integrations were built from provider documentation and tested against fakes. Payload details most likely to need adjustment on first contact: Paystack refund webhook matching, Cloudflare TUS upload headers and webhook signature format, Zoom participant-report fields.
2. **No load or penetration testing has been done.** `scripts/load/*.js` (k6) exist; 500-concurrent-user capacity is unproven. Query indexes exist for the main read paths, but no `EXPLAIN` review under production-size data has been done.
3. **No real deployment.** Nginx/PHP-FPM/Redis/Supervisor instructions are unexecuted. CI workflow (`.github/workflows/ci.yml`) has not run on GitHub; the E2E job in particular is untested outside this sandbox.
4. **Manual accessibility audit outstanding** (see ACCESSIBILITY.md).
5. **No real content or branding.** The seeded starter course is local-only. Academy name/legal details, terms, privacy policy and refund policy pages are marked "Draft for review" and **must be reviewed and replaced by the academy's lawyer** (Nigeria Data Protection Act 2023 obligations, consumer-protection rules) before launch. No photography or testimonials are shipped on purpose.
6. **Email** is `log` driver by default; verification and password reset require a real mail provider.
7. **YouTube lessons** cannot be fully protected: a determined user can watch without the tracker reporting; progress for YouTube is plausibility-checked, not tamper-proof. Use Cloudflare Stream for lessons that gate certificates. See VIDEO.md.
8. **Single currency (NGN)**, English only, Africa/Lagos display time zone. Multi-currency and i18n are not implemented.
9. **Files** (assignments, certificates, receipts) rely on the private bucket; the DB backup does not include them.
10. **ClamAV** scanning is optional and off by default; without it uploads are checked by size, type sniffing and extension only.
11. Dev-only seeders (`StarterSeeder`, `SampleDataSeeder`) refuse to run in production but must still not be invoked by deploy scripts.
12. The Windows local-install script (`scripts/setup-windows.ps1`) has not been run on a Windows machine.

13. **The help assistant is rules-based**, not generative AI: it matches keywords against about 20 curated topics plus catalogue search, so unusual phrasing falls back to "I am not sure" with suggestions and a route to a human. Topic wording (especially refunds and policy statements) must be reviewed by the academy. There is no live-agent chat or conversation history on the server.

14. **Two database engines are supported** (PostgreSQL 14+ and MySQL 8.0.16+/MariaDB 10.5+), chosen by `DB_CONNECTION`. On MySQL the tests pass on 8.0; MariaDB and the host's own engine are unverified. Differences: the "exactly one of support requester or email" rule is enforced by the application only (MySQL cannot combine a CHECK with the foreign key's SET NULL); append-only triggers need the TRIGGER privilege and may need `log_bin_trust_function_creators=1` (check Admin → System → "Tamper-proof logs", fix with `php artisan gospel:install-protections`); the restore test falls back to a dump-file check when the user cannot create a scratch database; the pg_trgm search index is PostgreSQL-only.
