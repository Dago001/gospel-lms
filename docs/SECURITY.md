# Security overview and checklist

## What is enforced, and where

| Area | Control |
|---|---|
| Authentication | Sanctum cookie session (HttpOnly, SameSite=lax, Secure in production), CSRF on all unsafe methods, per-email+IP and per-IP login throttling, password rules (min 10, mixed case, number; plus the breached-password check in production), session list with revocation, session regeneration at login |
| Privileged accounts | `admin` and `finance` must enrol TOTP (RFC 6238, replay-protected, hashed recovery codes) before any privileged route is reachable |
| Authorisation | Permission table checked server-side per route (`RequirePermission`) plus policies for ownership/enrolment. The SPA hides links but is never the control. Mass-assignment is whitelisted per model |
| Payments | Amounts calculated on the server from stored prices/coupons; Paystack transaction verified server-side (amount, currency, reference, order metadata, gateway mode); webhook HMAC-SHA512 verified; settlement idempotent under row locks; one success per order (partial unique index); mismatches and unknown outcomes recorded and frozen for review; refunds protected by balance checks |
| Learning integrity | Video progress, quiz scoring, grading, completion and certificates are computed on the server; answer keys never sent to students; exam deadlines enforced server-side with a short grace; append-only grade-change log and certificate-revocation log (DB triggers) |
| Uploads | Size caps, extension allow-list, **content sniffing** (finfo) rather than client-reported type, optional ClamAV scan, private storage, short-lived authorised downloads; HTML from lecturers/users sanitised with HTMLPurifier |
| Audit | Append-only `audit_logs` (triggers reject UPDATE/DELETE) for role changes, grade amendments, refunds, certificate revocations, settings and exports |
| Privacy | Consent records, self-service data export, anonymisation of closed accounts, retention purge job, public certificate verification discloses the minimum |
| Transport/headers | HTTPS + HSTS at Nginx, API responses carry a deny-all CSP (`default-src 'none'`), nosniff, referrer, permissions and frame-deny headers via middleware; the static SPA's headers/CSP are set in Nginx (add a CSP there that allows your own origin, the Paystack/Zoom/Cloudflare Stream/YouTube embeds actually used, and nothing else) |
| Abuse | Named per-route rate limits (see `docs/API.md`), contact form honeypot, signed email links |
| Secrets | Only in `.env`/secret manager; none in the repository; integrations screen never reveals values; logs do not contain card data (cards never touch this system) |

## Help assistant (chatbot)

`POST /api/v1/chat/message` is a **rules-based assistant, not an AI model**: no text is sent to a third party and nothing is generated freely. Answers come from the vetted topic list in `backend/resources/chatbot/knowledge.php`, from read-only catalogue queries (published courses only) and, for a signed-in user, from that user's own enrolments. Controls, each covered by `tests/Feature/ChatAssistantTest.php` and `frontend/e2e/chat.spec.js`:

| Threat | Control |
|---|---|
| SQL injection | No raw SQL anywhere in the path. Text is normalised to letters/digits, at most 3 tokens of 2–30 characters are used, and each is a bound parameter to Eloquent (`ilike` with `%`, `_` and `\` escaped), so payloads such as `'; DROP TABLE courses;--`, `' OR 1=1 --`, `UNION SELECT` and `pg_sleep` are inert. Tests assert the tables and row counts are untouched and that `%` does not match every course |
| XSS / HTML injection | The UI renders replies as React text (no `dangerouslySetInnerHTML`); the server never echoes the raw message, only tokeniser output; tests post `<script>`/`<img onerror>` and assert nothing executes or is reflected |
| Open redirect / link injection | Server emits only in-app paths and the client re-validates every link against `^/(?!/)…` before rendering |
| Data exposure | Guests get public information only; "my courses" uses the authenticated user's id (never a client-supplied id); drafts and unpublished courses are never searched; certificate lookup reuses the public verification endpoint's minimal disclosure and logging |
| Abuse / DoS | 300-character cap (validated, 422 above it), control characters stripped, array/non-string input rejected, limiter of 15 messages/min per user-or-IP and 200/hour per IP |
| Privacy | Stateless: no conversation tables, no logging of message text. History lives only in the visitor's `sessionStorage` and is cleared with the tab |
| Prompt injection | Not applicable (no LLM). If an LLM backend is added later it must run behind the same validation, answer only from retrieved published content, have no tool access to user data, and be tested with adversarial prompts |

## Pre-launch checklist (not yet completed by this repository's author)

- [ ] Independent penetration test / security review of the staging deployment
- [ ] `composer audit` and `npm audit` clean or exceptions justified (CI runs both)
- [ ] `APP_DEBUG=false`, `APP_ENV=production`; no starter/E2E seeders run
- [ ] TLS A-grade, HSTS preloaded decision made, HTTP→HTTPS redirect verified
- [ ] First admin has TOTP + recovery codes stored offline
- [ ] DB user least-privilege; DB and Redis not reachable from the internet
- [ ] Bucket private; no public ACLs; versioning on
- [ ] Paystack live keys set last; `PAYSTACK_MODE=live`; webhook IP allow-list considered
- [ ] Backups restored successfully at least once from the off-site copy
- [ ] Rate limits reviewed against real traffic; WAF/CDN in front if desired
- [ ] Incident contact and data-breach (NDPA) procedure written down

Report vulnerabilities privately to the academy's support address; do not open public issues for security problems.
