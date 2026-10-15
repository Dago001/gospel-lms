# GOSPEL LMS: API reference

Base path `/api/v1`. JSON in, JSON out. Authentication is the Laravel Sanctum **SPA cookie session**: there are no API tokens and nothing secret is held in JavaScript.

The route tables below are generated from `php artisan route:list --path=api/v1 --json` (215 routes), so they list exactly what is registered. Re-run that command and update the tables after changing routes. Request/response field details live in the controllers' `validate()` calls and the Pest feature tests in `backend/tests/Feature`, which are executable examples.

## Conventions

* **Session & CSRF.** Call `GET /sanctum/csrf-cookie` once, then send the `XSRF-TOKEN` cookie value back in the `X-XSRF-TOKEN` header on every `POST/PUT/PATCH/DELETE`. Requests must come from a stateful origin (`SANCTUM_STATEFUL_DOMAINS`). Send `Accept: application/json`.
* **Guests.** `GET /auth/session` returns `200 {"data": null}` for a guest (no console noise); other protected routes return `401`.
* **Success shape.** Resources: `{"data": ...}`. Lists: `{"data": [...], "meta": {...pagination}}`. Auth endpoints return `{"status": "authenticated" | "two_factor_required", "user": {...}}`.
* **Errors.** `{"message": "...", "code": "optional_machine_code", "errors": {"field": ["..."]}}` with status `401` (not signed in), `403` (not allowed / MFA required), `404`, `409` (state conflict, e.g. already enrolled), `419` (CSRF expired), `422` (validation or business rule), `429` (rate limited).
* **Money** is always integer **kobo** (`2500000` = ₦25,000.00) with an ISO currency (`NGN`).
* **IDs** are UUIDs. **Timestamps** are ISO 8601 UTC; the UI renders them in `Africa/Lagos`.
* **Access column.** `signed-in` = Sanctum cookie session; `MFA` = the `mfa` middleware, which only blocks admin/finance accounts that have not completed TOTP in this session; `perm: x` = the permission is checked server-side by `RequirePermission` (a role never grants access by name). Ownership/enrolment rules (a learner reading only their own enrolment, a lecturer editing only their own course) are enforced in policies and services on top of this and are not visible in the table.
* **Throttle** = `requests,minutes,key` or a named limiter defined in `AppServiceProvider`. Each route has its own counter.
* **Webhooks** (`/webhooks/paystack`, `/webhooks/zoom`, `/webhooks/video/cloudflare`) are unauthenticated by design and authenticated by HMAC signature; unsigned or mis-signed payloads are rejected and, for payments, recorded.

## Examples

```http
GET /sanctum/csrf-cookie            -> 204 + XSRF-TOKEN cookie

POST /api/v1/auth/login
{"email":"student@example.com","password":"..."}
-> 200 {"status":"authenticated","user":{"id":"...","roles":["student"],"email_verified":true,...}}
-> 200 {"status":"two_factor_required"}   (privileged accounts; continue with POST /auth/two-factor/challenge {"code":"123456"})
-> 422 {"message":"These credentials do not match our records.","errors":{"email":["..."]}}
-> 429 after 5 failed attempts/minute per email+IP

POST /api/v1/checkout/preview     (priced on the server; nothing is created)
{"course_ids":["<uuid>"],"coupon_code":"EASTER10"}
-> 200 {"data":{"currency":"NGN","subtotal_minor":2500000,"discount_minor":250000,"total_minor":2250000,"items":[{"course_id":"<uuid>","title":"...","unit_price_minor":2500000,"discount_minor":250000}]}}

POST /api/v1/checkout             (same body; creates the order and initialises the Paystack transaction)
-> 200 {"data":{...order..., "payment":{"reference":"...","status":"pending","authorization_url":"https://checkout.paystack.com/..."}}}
-> 409 already enrolled | 422 unverified email / invalid coupon / unpublished course

POST /api/v1/payments/{reference}/verify   (called when the learner returns from Paystack; the server asks Paystack, the client's claim is ignored)
-> 200 {"data":{"status":"success|pending|failed","order":{...},"receipt_id":"..."}}
-> 202 {"data":{"status":"pending","message":"...confirmed automatically."}}   (gateway unreachable; the webhook/reconciler settles it)
```

Access to the course is granted only after the server verifies the payment with Paystack (callback verify or signed webhook); the redirect URL alone proves nothing, and this is covered by the "forging the callback URL cannot unlock a course" E2E test.

## Routes

### `account`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| GET | `/api/v1/account/consents` | PrivacyController@consents | signed-in, MFA |  |
| GET | `/api/v1/account/data-exports` | PrivacyController@exports | signed-in, MFA |  |
| POST | `/api/v1/account/data-exports` | PrivacyController@requestExport | signed-in, MFA | 3,60,dataexport |
| GET | `/api/v1/account/data-exports/{export}/download` | PrivacyController@download | signed-in, MFA | 10,60,exportdl |
| PUT | `/api/v1/account/password` | AccountController@updatePassword | signed-in, MFA | password-change |
| POST | `/api/v1/account/photo` | AccountController@uploadPhoto | signed-in, MFA |  |
| GET | `/api/v1/account/photo` | AccountController@photo | signed-in, MFA |  |
| PUT | `/api/v1/account/profile` | AccountController@updateProfile | signed-in, MFA |  |
| GET | `/api/v1/account/sessions` | AccountController@sessions | signed-in, MFA |  |
| DELETE | `/api/v1/account/sessions/{session}` | AccountController@revokeSession | signed-in, MFA |  |

### `admin`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| POST | `/api/v1/admin/announcements` | AnnouncementController@storePlatform | signed-in, MFA, perm: notifications.manage | 10,60,platformann |
| DELETE | `/api/v1/admin/announcements/{announcement}` | AnnouncementController@destroyPlatform | signed-in, MFA, perm: notifications.manage |  |
| GET | `/api/v1/admin/audit-logs` | SystemController@auditLogs | signed-in, MFA, perm: audit.view | 30,1,auditlog |
| GET | `/api/v1/admin/backups` | SystemController@backups | signed-in, MFA, perm: settings.manage |  |
| GET | `/api/v1/admin/categories` | CategoryController@index | signed-in, MFA, perm: categories.manage |  |
| POST | `/api/v1/admin/categories` | CategoryController@store | signed-in, MFA, perm: categories.manage |  |
| PUT | `/api/v1/admin/categories/{category}` | CategoryController@update | signed-in, MFA, perm: categories.manage |  |
| DELETE | `/api/v1/admin/categories/{category}` | CategoryController@destroy | signed-in, MFA, perm: categories.manage |  |
| GET | `/api/v1/admin/certificates` | CertificateController@index | signed-in, MFA, perm: certificates.manage |  |
| POST | `/api/v1/admin/certificates/{certificate}/reissue` | CertificateController@reissue | signed-in, MFA, perm: certificates.manage | 20,1,r32 |
| POST | `/api/v1/admin/certificates/{certificate}/revoke` | CertificateController@revoke | signed-in, MFA, perm: certificates.manage | 20,1,r31 |
| GET | `/api/v1/admin/dashboard` | DashboardController@admin | signed-in, MFA, perm: reports.academic,reports.finance |  |
| GET | `/api/v1/admin/enrolments` | EnrolmentController@index | signed-in, MFA, perm: enrolments.manage |  |
| POST | `/api/v1/admin/enrolments` | EnrolmentController@store | signed-in, MFA, perm: enrolments.manage |  |
| PUT | `/api/v1/admin/enrolments/{enrollment}` | EnrolmentController@update | signed-in, MFA, perm: enrolments.manage |  |
| GET | `/api/v1/admin/finance/coupons` | FinanceController@coupons | signed-in, MFA, perm: coupons.manage |  |
| POST | `/api/v1/admin/finance/coupons` | FinanceController@storeCoupon | signed-in, MFA, perm: coupons.manage |  |
| PUT | `/api/v1/admin/finance/coupons/{coupon}` | FinanceController@updateCoupon | signed-in, MFA, perm: coupons.manage |  |
| GET | `/api/v1/admin/finance/exceptions` | FinanceController@exceptions | signed-in, MFA, perm: finance.view |  |
| GET | `/api/v1/admin/finance/export` | FinanceController@export | signed-in, MFA, perm: reports.finance | 10,1,r28 |
| GET | `/api/v1/admin/finance/orders` | FinanceController@orders | signed-in, MFA, perm: finance.view |  |
| GET | `/api/v1/admin/finance/payments` | FinanceController@payments | signed-in, MFA, perm: finance.view |  |
| GET | `/api/v1/admin/finance/payments/{payment}` | FinanceController@payment | signed-in, MFA, perm: finance.view |  |
| POST | `/api/v1/admin/finance/payments/{payment}/reconcile` | FinanceController@reconcile | signed-in, MFA, perm: finance.reconcile | 30,1,r29 |
| POST | `/api/v1/admin/finance/payments/{payment}/refunds` | FinanceController@refund | signed-in, MFA, perm: finance.refund | 10,1,r30 |
| GET | `/api/v1/admin/finance/refunds` | FinanceController@refunds | signed-in, MFA, perm: finance.view |  |
| GET | `/api/v1/admin/finance/summary` | FinanceController@summary | signed-in, MFA, perm: reports.finance |  |
| GET | `/api/v1/admin/finance/webhooks` | FinanceController@webhooks | signed-in, MFA, perm: finance.view |  |
| GET | `/api/v1/admin/health` | SystemController@health | signed-in, MFA, perm: settings.manage |  |
| GET | `/api/v1/admin/integrations` | SystemController@integrations | signed-in, MFA, perm: settings.manage |  |
| GET | `/api/v1/admin/notifications/log` | NotificationAdminController@log | signed-in, MFA, perm: notifications.manage |  |
| GET | `/api/v1/admin/notifications/summary` | NotificationAdminController@summary | signed-in, MFA, perm: notifications.manage |  |
| GET | `/api/v1/admin/reports/assessments` | ReportController@assessments | signed-in, MFA, perm: reports.academic | 30,1,repassessments |
| GET | `/api/v1/admin/reports/attendance` | ReportController@attendance | signed-in, MFA, perm: reports.academic | 30,1,repattendance |
| GET | `/api/v1/admin/reports/certificates` | ReportController@certificates | signed-in, MFA, perm: reports.academic | 30,1,repcertificates |
| GET | `/api/v1/admin/reports/completion` | ReportController@completion | signed-in, MFA, perm: reports.academic | 30,1,repcompletion |
| GET | `/api/v1/admin/reports/enrolments` | ReportController@enrolments | signed-in, MFA, perm: reports.academic | 30,1,repenrolments |
| GET | `/api/v1/admin/reports/grading-queue` | ReportController@gradingQueue | signed-in, MFA, perm: reports.academic | 30,1,repgrading-queue |
| GET | `/api/v1/admin/roles` | RoleController@index | signed-in, MFA, perm: roles.manage |  |
| PUT | `/api/v1/admin/roles/{role}/permissions` | RoleController@syncPermissions | signed-in, MFA, perm: roles.manage |  |
| GET | `/api/v1/admin/settings` | SystemController@settings | signed-in, MFA, perm: settings.manage |  |
| PUT | `/api/v1/admin/settings` | SystemController@updateSettings | signed-in, MFA, perm: settings.manage |  |
| GET | `/api/v1/admin/support/tickets` | SupportController@staffIndex | signed-in, MFA, perm: support.manage |  |
| GET | `/api/v1/admin/support/tickets/{ticket}` | SupportController@staffShow | signed-in, MFA, perm: support.manage |  |
| PUT | `/api/v1/admin/support/tickets/{ticket}` | SupportController@staffUpdate | signed-in, MFA, perm: support.manage |  |
| POST | `/api/v1/admin/support/tickets/{ticket}/messages` | SupportController@staffReply | signed-in, MFA, perm: support.manage | 60,1,staffreply |
| GET | `/api/v1/admin/users` | UserController@index | signed-in, MFA, perm: users.view |  |
| POST | `/api/v1/admin/users` | UserController@store | signed-in, MFA, perm: users.manage |  |
| GET | `/api/v1/admin/users/{user}` | UserController@show | signed-in, MFA, perm: users.view |  |
| POST | `/api/v1/admin/users/{user}/anonymise` | UserController@anonymise | signed-in, MFA, perm: users.manage | 10,1,anon |
| POST | `/api/v1/admin/users/{user}/reactivate` | UserController@reactivate | signed-in, MFA, perm: users.manage |  |
| PUT | `/api/v1/admin/users/{user}/roles` | UserController@updateRoles | signed-in, MFA, perm: users.manage |  |
| POST | `/api/v1/admin/users/{user}/suspend` | UserController@suspend | signed-in, MFA, perm: users.manage |  |

### `announcements`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| GET | `/api/v1/announcements` | AnnouncementController@platform | signed-in |  |

### `auth`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| POST | `/api/v1/auth/email/resend` | AuthController@resendVerification | signed-in | 6,1,r6 |
| GET | `/api/v1/auth/email/verify/{id}/{hash}` | AuthController@verifyEmail | signed URL | 6,1,r1 |
| POST | `/api/v1/auth/forgot-password` | AuthController@forgotPassword | public | password-reset |
| POST | `/api/v1/auth/login` | AuthController@login | public | login |
| POST | `/api/v1/auth/logout` | AuthController@logout | signed-in |  |
| GET | `/api/v1/auth/me` | AuthController@me | signed-in |  |
| POST | `/api/v1/auth/register` | AuthController@register | public | register |
| POST | `/api/v1/auth/reset-password` | AuthController@resetPassword | public | password-reset |
| GET | `/api/v1/auth/session` | AuthController@session | public |  |
| POST | `/api/v1/auth/two-factor/challenge` | AuthController@twoFactorChallenge | public | mfa |
| POST | `/api/v1/auth/two-factor/confirm` | TwoFactorController@confirm | signed-in | mfa |
| POST | `/api/v1/auth/two-factor/disable` | TwoFactorController@disable | signed-in, MFA | mfa |
| POST | `/api/v1/auth/two-factor/setup` | TwoFactorController@setup | signed-in | mfa |

### `checkout`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| POST | `/api/v1/checkout` | CheckoutController@start | signed-in, MFA | 10,1,r8 |
| POST | `/api/v1/checkout/preview` | CheckoutController@preview | signed-in, MFA | 30,1,r7 |

### `learn`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| POST | `/api/v1/learn/assessments/{assessment}/attempts` | AssessmentLearnController@start | signed-in, MFA | 20,1,r17 |
| GET | `/api/v1/learn/assignments/{assignment}` | AssignmentLearnController@show | signed-in, MFA |  |
| POST | `/api/v1/learn/assignments/{assignment}/submissions` | AssignmentLearnController@submit | signed-in, MFA | 20,1,r15 |
| GET | `/api/v1/learn/attempts/{attempt}` | AssessmentLearnController@show | signed-in, MFA |  |
| PUT | `/api/v1/learn/attempts/{attempt}/answers/{questionId}` | AssessmentLearnController@saveAnswer | signed-in, MFA | 300,1,r18 |
| GET | `/api/v1/learn/attempts/{attempt}/result` | AssessmentLearnController@result | signed-in, MFA |  |
| POST | `/api/v1/learn/attempts/{attempt}/submit` | AssessmentLearnController@submit | signed-in, MFA | 20,1,r19 |
| GET | `/api/v1/learn/certificates` | CompletionController@certificates | signed-in, MFA |  |
| GET | `/api/v1/learn/certificates/{certificate}/pdf` | CompletionController@pdf | signed-in, MFA | 30,1,r21 |
| GET | `/api/v1/learn/courses` | LearnController@myCourses | signed-in, MFA |  |
| GET | `/api/v1/learn/courses/{course}` | LearnController@course | signed-in, MFA |  |
| GET | `/api/v1/learn/courses/{course}/announcements` | AnnouncementController@forCourse | signed-in, MFA |  |
| GET | `/api/v1/learn/courses/{course}/assessments` | AssessmentLearnController@index | signed-in, MFA |  |
| GET | `/api/v1/learn/courses/{course}/assignments` | AssignmentLearnController@index | signed-in, MFA |  |
| GET | `/api/v1/learn/courses/{course}/completion` | CompletionController@show | signed-in, MFA | 60,1,r20 |
| POST | `/api/v1/learn/courses/{course}/enrol` | LearnController@enrolFree | signed-in, MFA | 10,1,r10 |
| GET | `/api/v1/learn/courses/{course}/live-sessions` | LiveLearnController@index | signed-in, MFA |  |
| GET | `/api/v1/learn/courses/{course}/progress` | PlaybackController@progress | signed-in, MFA |  |
| GET | `/api/v1/learn/courses/{course}/threads` | ForumController@index | signed-in, MFA |  |
| POST | `/api/v1/learn/courses/{course}/threads` | ForumController@store | signed-in, MFA | 10,10,threadcreate |
| GET | `/api/v1/learn/lessons/{lesson}` | LearnController@lesson | signed-in, MFA |  |
| POST | `/api/v1/learn/lessons/{lesson}/complete` | PlaybackController@complete | signed-in, MFA | 30,1,r14 |
| POST | `/api/v1/learn/lessons/{lesson}/playback` | PlaybackController@start | signed-in, MFA | 30,1,r11 |
| GET | `/api/v1/learn/live-sessions/{session}/join` | LiveLearnController@join | signed-in, MFA | 30,1,livejoin |
| GET | `/api/v1/learn/live-sessions/{session}/recordings` | LiveLearnController@recordings | signed-in, MFA |  |
| POST | `/api/v1/learn/playback/{session}/events` | PlaybackController@events | signed-in, MFA | 120,1,r12 |
| POST | `/api/v1/learn/playback/{session}/token` | PlaybackController@refreshToken | signed-in, MFA | 30,1,r13 |
| PUT | `/api/v1/learn/posts/{post}` | ForumController@editPost | signed-in, MFA |  |
| DELETE | `/api/v1/learn/posts/{post}` | ForumController@deletePost | signed-in, MFA |  |
| POST | `/api/v1/learn/resources/{resource}/acknowledge` | PlaybackController@acknowledge | signed-in, MFA |  |
| GET | `/api/v1/learn/resources/{resource}/download` | LearnController@downloadResource | signed-in, MFA | 60,1,r22 |
| GET | `/api/v1/learn/submission-files/{file}` | AssignmentLearnController@downloadFile | signed-in, MFA | 60,1,r16 |
| GET | `/api/v1/learn/threads/{thread}` | ForumController@show | signed-in, MFA |  |
| PUT | `/api/v1/learn/threads/{thread}` | ForumController@moderate | signed-in, MFA |  |
| DELETE | `/api/v1/learn/threads/{thread}` | ForumController@deleteThread | signed-in, MFA |  |
| POST | `/api/v1/learn/threads/{thread}/posts` | ForumController@reply | signed-in, MFA | 30,10,threadreply |

### `manage`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| DELETE | `/api/v1/manage/announcements/{announcement}` | AnnouncementController@destroy | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/assessments/{assessment}` | AssessmentController@show | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/assessments/{assessment}` | AssessmentController@update | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/assessments/{assessment}/attempts` | AssessmentController@attempts | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/assessments/{assessment}/grant-attempts` | AssessmentController@grantAttempts | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/assessments/{assessment}/publish` | AssessmentController@publish | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/assessments/{assessment}/publish-results` | AssessmentController@publishResults | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/assignments/{assignment}` | AssignmentController@show | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/assignments/{assignment}` | AssignmentController@update | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/assignments/{assignment}/publish` | AssignmentController@publish | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/assignments/{assignment}/submissions` | AssignmentController@submissions | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/attempts/{attempt}` | AssessmentController@attempt | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/attempts/{attempt}/responses/{questionId}/mark` | AssessmentController@markResponse | signed-in, MFA, perm: courses.manage-own,courses.manage-all | 300,1,r25 |
| GET | `/api/v1/manage/courses` | CourseController@index | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses` | CourseController@store | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}` | CourseController@show | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/courses/{course}` | CourseController@update | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| DELETE | `/api/v1/manage/courses/{course}` | CourseController@destroy | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}/analytics` | ReportController@course | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/announcements` | AnnouncementController@store | signed-in, MFA, perm: courses.manage-own,courses.manage-all | 20,60,annpost |
| POST | `/api/v1/manage/courses/{course}/approve` | CourseController@approve | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/archive` | CourseController@archive | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}/assessments` | AssessmentController@index | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/assessments` | AssessmentController@store | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}/assignments` | AssignmentController@index | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/assignments` | AssignmentController@store | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}/completion-policy` | CompletionController@policy | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/courses/{course}/completion-policy` | CompletionController@updatePolicy | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}/completion-report` | CompletionController@report | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}/live-sessions` | LiveSessionController@index | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/live-sessions` | LiveSessionController@store | signed-in, MFA, perm: courses.manage-own,courses.manage-all | 20,1,livecreate |
| POST | `/api/v1/manage/courses/{course}/modules` | CurriculumController@storeModule | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/courses/{course}/modules/order` | CurriculumController@reorderModules | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/courses/{course}/prerequisites` | CourseController@prerequisites | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/courses/{course}/pricing` | CourseController@pricing | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/publish` | CourseController@publish | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}/question-banks` | AssessmentController@banks | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/question-banks` | AssessmentController@storeBank | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/reject` | CourseController@reject | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/restore` | CourseController@restore | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}/rubrics` | AssignmentController@rubrics | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/rubrics` | AssignmentController@storeRubric | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/students/{user}/approve-completion` | CompletionController@approve | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}/students/{user}/completion` | CompletionController@student | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/courses/{course}/submit` | CourseController@submit | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/courses/{course}/versions` | CourseController@versions | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/dashboard` | DashboardController@lecturer | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/lessons/{lesson}` | CurriculumController@updateLesson | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| DELETE | `/api/v1/manage/lessons/{lesson}` | CurriculumController@destroyLesson | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/lessons/{lesson}/resources` | CurriculumController@uploadResource | signed-in, MFA, perm: courses.manage-own,courses.manage-all | 30,1,r27 |
| POST | `/api/v1/manage/lessons/{lesson}/video/upload` | VideoController@authoriseUpload | signed-in, MFA, perm: courses.manage-own,courses.manage-all | 20,1,r26 |
| PUT | `/api/v1/manage/live-sessions/{session}` | LiveSessionController@update | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| DELETE | `/api/v1/manage/live-sessions/{session}` | LiveSessionController@destroy | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/live-sessions/{session}/attendance` | LiveSessionController@attendance | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/live-sessions/{session}/attendance/{user}` | LiveSessionController@setAttendance | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/live-sessions/{session}/recordings` | LiveSessionController@recordings | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/live-sessions/{session}/sync-attendance` | LiveSessionController@syncNow | signed-in, MFA, perm: courses.manage-own,courses.manage-all | 10,1,livesync |
| PUT | `/api/v1/manage/modules/{module}` | CurriculumController@updateModule | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| DELETE | `/api/v1/manage/modules/{module}` | CurriculumController@destroyModule | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/modules/{module}/lessons` | CurriculumController@storeLesson | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/modules/{module}/lessons/order` | CurriculumController@reorderLessons | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/question-banks/{bank}/questions` | AssessmentController@questions | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/question-banks/{bank}/questions` | AssessmentController@storeQuestion | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/questions/{question}` | AssessmentController@updateQuestion | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| DELETE | `/api/v1/manage/questions/{question}` | AssessmentController@archiveQuestion | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| PUT | `/api/v1/manage/recordings/{recording}` | LiveSessionController@publishRecording | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| DELETE | `/api/v1/manage/resources/{resource}` | CurriculumController@destroyResource | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/submission-files/{file}` | AssignmentController@downloadFile | signed-in, MFA, perm: courses.manage-own,courses.manage-all | 120,1,r24 |
| GET | `/api/v1/manage/submissions/{submission}` | AssignmentController@submission | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| POST | `/api/v1/manage/submissions/{submission}/grade` | AssignmentController@grade | signed-in, MFA, perm: courses.manage-own,courses.manage-all | 120,1,r23 |
| POST | `/api/v1/manage/submissions/{submission}/return` | AssignmentController@returnForRevision | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |
| GET | `/api/v1/manage/video-assets/{asset}` | VideoController@status | signed-in, MFA, perm: courses.manage-own,courses.manage-all |  |

### `notifications`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| GET | `/api/v1/notifications` | NotificationController@index | signed-in |  |
| GET | `/api/v1/notifications/preferences` | NotificationController@preferences | signed-in |  |
| PUT | `/api/v1/notifications/preferences` | NotificationController@updatePreferences | signed-in |  |
| POST | `/api/v1/notifications/read-all` | NotificationController@readAll | signed-in |  |
| GET | `/api/v1/notifications/unread-count` | NotificationController@unreadCount | signed-in |  |
| POST | `/api/v1/notifications/{id}/read` | NotificationController@read | signed-in |  |

### `payments`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| POST | `/api/v1/payments/{reference}/verify` | CheckoutController@verify | signed-in, MFA | 20,1,r9 |

### `chat`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| GET | `/api/v1/chat/greeting` | ChatController@greeting | public | 60,1,chatgreet |
| POST | `/api/v1/chat/message` | ChatController@message | public (user-aware) | chat (15/min per user or IP, 200/h per IP) |

Body `{"message": "<=300 chars"}`; response `{"data": {"intent", "reply", "cards": [{title, subtitle, url}], "links": [{label, url}], "suggestions": []}}`.

### `public`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| GET | `/api/v1/public/categories` | CatalogueController@categories | public | 120,1,r4 |
| GET | `/api/v1/public/certificates/verify/{code}` | CertificateVerifyController@show | public | 30,1,r5 |
| POST | `/api/v1/public/contact` | SupportController@contact | public | 5,60,contactform |
| GET | `/api/v1/public/courses` | CatalogueController@courses | public | 120,1,r4 |
| GET | `/api/v1/public/courses/{slug}` | CatalogueController@course | public | 120,1,r4 |
| GET | `/api/v1/public/lecturers` | CatalogueController@lecturers | public | 120,1,r4 |
| GET | `/api/v1/public/lecturers/{id}` | CatalogueController@lecturer | public | 120,1,r4 |
| GET | `/api/v1/public/site` | CatalogueController@site | public | 120,1,r4 |

### `student`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| GET | `/api/v1/student/dashboard` | DashboardController@student | signed-in, MFA |  |
| GET | `/api/v1/student/orders` | CheckoutController@orders | signed-in, MFA |  |
| GET | `/api/v1/student/receipts` | CheckoutController@receipts | signed-in, MFA |  |
| GET | `/api/v1/student/receipts/{receipt}/pdf` | CheckoutController@receiptPdf | signed-in, MFA |  |
| GET | `/api/v1/student/results` | DashboardController@results | signed-in, MFA |  |

### `support`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| GET | `/api/v1/support/tickets` | SupportController@mine | signed-in |  |
| POST | `/api/v1/support/tickets` | SupportController@create | signed-in | 10,60,ticketcreate |
| GET | `/api/v1/support/tickets/{ticket}` | SupportController@show | signed-in |  |
| POST | `/api/v1/support/tickets/{ticket}/close` | SupportController@close | signed-in |  |
| POST | `/api/v1/support/tickets/{ticket}/messages` | SupportController@reply | signed-in | 30,60,ticketreply |

### `webhooks`

| Method | Path | Handler | Access | Throttle |
|---|---|---|---|---|
| POST | `/api/v1/webhooks/paystack` | PaymentWebhookController@paystack | public | 300,1,r2 |
| POST | `/api/v1/webhooks/video/cloudflare` | VideoWebhookController@cloudflare | public | 300,1,r3 |
| POST | `/api/v1/webhooks/zoom` | ZoomWebhookController@handle | public | 300,1,zoomhook |
