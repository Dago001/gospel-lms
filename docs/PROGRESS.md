# GOSPEL LMS - implementation progress

Status key: **Done+tested** = implemented and covered by automated tests that pass on PostgreSQL. **Implemented** = code exists, not yet exercised against the real external service. **Not started**.

Last full backend run: `php vendor/bin/pest` -> 133 passed (PostgreSQL 16, PHP 8.3, Laravel 13.36).

| Phase | Area | Status |
|---|---|---|
| 2 | Laravel 13 + React/Vite foundation, PostgreSQL, Pest | Done+tested (backend). Frontend is still the Vite starter page |
| 3 | Identity, RBAC, MFA (TOTP+recovery), sessions, audit log, rate limits, security headers | Done+tested |
| 4 | Categories, courses, modules, lessons, documents, workflow, versions, catalogue, learner access | Done+tested |
| 5 | Checkout, Paystack init/verify/webhook, coupons, refunds, receipts (PDF), reconciliation, finance API | Done+tested against **faked** Paystack HTTP. Real sandbox run pending credentials (`php artisan paystack:smoke`) |
| 6 | Cloudflare Stream hosting, YouTube, server-validated progress, lesson locking | Done+tested against fakes; real Cloudflare account run pending credentials. See docs/VIDEO.md |
| 7 | Assignments, rubrics, late policy, auditable grading | Done+tested |
| 8 | Question banks, quizzes, exams | Not started |
| 9 | Zoom, attendance, notification centre | Not started (notifications table + payment notification exist) |
| 10 | Completion engine, certificates, QR, verification | Not started (PDF + QR libraries installed) |
| 11 | Admin portal UI, reports, accessibility, load tests | Not started |
| 12 | Deployment, backup/restore test | Not started |
| - | React application (public site, portals, design system) | Not started |

## Blocked on you
- Paystack **test** keys (`PAYSTACK_SECRET_KEY`, `PAYSTACK_PUBLIC_KEY`) and a webhook URL reachable from the internet (staging) to prove the real gateway round-trip. Refund-webhook payload matching is based on Paystack's documented shape and is **unverified** until run in the sandbox.
- Zoom (Server-to-Server OAuth app) credentials.
- Video provider account (Cloudflare Stream or Bunny Stream) - selection still to be made.
- Real academy details for receipts/certificates (`ACADEMY_*` env values).
