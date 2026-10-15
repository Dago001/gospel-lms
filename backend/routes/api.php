<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\Learn\AssignmentLearnController;
use App\Http\Controllers\Api\V1\Learn\AssessmentLearnController;
use App\Http\Controllers\Api\V1\Learn\CompletionController;
use App\Http\Controllers\Api\V1\Learn\LearnController;
use App\Http\Controllers\Api\V1\Learn\PlaybackController;
use App\Http\Controllers\Api\V1\VideoWebhookController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Manage;
use App\Http\Controllers\Api\V1\Public\CatalogueController;
use App\Http\Controllers\Api\V1\Public\CertificateVerifyController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public authentication endpoints (rate limited by named limiters in AppServiceProvider)
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('two-factor/challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:mfa');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
        Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware(['signed', 'throttle:6,1,r1'])->name('verification.verify');
    });

    // Gateway webhook: authenticated by HMAC signature, not by session
    Route::post('webhooks/paystack', [PaymentWebhookController::class, 'paystack'])->middleware('throttle:300,1,r2');

    Route::post('webhooks/video/cloudflare', [VideoWebhookController::class, 'cloudflare'])->middleware('throttle:300,1,r3');

    // Public catalogue (published content only)
    Route::prefix('public')->middleware('throttle:120,1,r4')->group(function () {
        Route::get('categories', [CatalogueController::class, 'categories']);
        Route::get('courses', [CatalogueController::class, 'courses']);
        Route::get('courses/{slug}', [CatalogueController::class, 'course']);
        Route::get('lecturers', [CatalogueController::class, 'lecturers']);
        Route::get('lecturers/{id}', [CatalogueController::class, 'lecturer']);
        Route::get('certificates/verify/{code}', [CertificateVerifyController::class, 'show'])->middleware('throttle:30,1,r5');
    });

    // Authenticated (session) endpoints
    Route::middleware(['auth:sanctum', 'active.session'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:6,1,r6');

        Route::prefix('auth/two-factor')->middleware('throttle:mfa')->group(function () {
            Route::post('setup', [TwoFactorController::class, 'setup']);
            Route::post('confirm', [TwoFactorController::class, 'confirm']);
            Route::post('disable', [TwoFactorController::class, 'disable'])->middleware('mfa');
        });

        Route::prefix('account')->middleware('mfa')->group(function () {
            Route::put('profile', [AccountController::class, 'updateProfile']);
            Route::put('password', [AccountController::class, 'updatePassword'])->middleware('throttle:password-change');
            Route::post('photo', [AccountController::class, 'uploadPhoto']);
            Route::get('photo', [AccountController::class, 'photo']);
            Route::get('sessions', [AccountController::class, 'sessions']);
            Route::delete('sessions/{session}', [AccountController::class, 'revokeSession']);
        });

        // Checkout and billing (students)
        Route::middleware('mfa')->group(function () {
            Route::post('checkout/preview', [CheckoutController::class, 'preview'])->middleware('throttle:30,1,r7');
            Route::post('checkout', [CheckoutController::class, 'start'])->middleware('throttle:10,1,r8');
            Route::post('payments/{reference}/verify', [CheckoutController::class, 'verify'])->middleware('throttle:20,1,r9');
            Route::get('student/orders', [CheckoutController::class, 'orders']);
            Route::get('student/receipts', [CheckoutController::class, 'receipts']);
            Route::get('student/receipts/{receipt}/pdf', [CheckoutController::class, 'receiptPdf']);
        });

        // Learning (students and staff previewing)
        Route::prefix('learn')->middleware('mfa')->group(function () {
            Route::get('courses', [LearnController::class, 'myCourses']);
            Route::post('courses/{course}/enrol', [LearnController::class, 'enrolFree'])->middleware('throttle:10,1,r10');
            Route::get('courses/{course}', [LearnController::class, 'course']);
            Route::get('lessons/{lesson}', [LearnController::class, 'lesson']);
            Route::post('lessons/{lesson}/playback', [PlaybackController::class, 'start'])->middleware('throttle:30,1,r11');
            Route::post('playback/{session}/events', [PlaybackController::class, 'events'])->middleware('throttle:120,1,r12');
            Route::post('playback/{session}/token', [PlaybackController::class, 'refreshToken'])->middleware('throttle:30,1,r13');
            Route::post('lessons/{lesson}/complete', [PlaybackController::class, 'complete'])->middleware('throttle:30,1,r14');
            Route::post('resources/{resource}/acknowledge', [PlaybackController::class, 'acknowledge']);
            Route::get('courses/{course}/progress', [PlaybackController::class, 'progress']);
            Route::get('courses/{course}/assignments', [AssignmentLearnController::class, 'index']);
            Route::get('assignments/{assignment}', [AssignmentLearnController::class, 'show']);
            Route::post('assignments/{assignment}/submissions', [AssignmentLearnController::class, 'submit'])->middleware('throttle:20,1,r15');
            Route::get('submission-files/{file}', [AssignmentLearnController::class, 'downloadFile'])->middleware('throttle:60,1,r16');
            Route::get('courses/{course}/assessments', [AssessmentLearnController::class, 'index']);
            Route::post('assessments/{assessment}/attempts', [AssessmentLearnController::class, 'start'])->middleware('throttle:20,1,r17');
            Route::get('attempts/{attempt}', [AssessmentLearnController::class, 'show']);
            Route::put('attempts/{attempt}/answers/{questionId}', [AssessmentLearnController::class, 'saveAnswer'])->middleware('throttle:300,1,r18');
            Route::post('attempts/{attempt}/submit', [AssessmentLearnController::class, 'submit'])->middleware('throttle:20,1,r19');
            Route::get('attempts/{attempt}/result', [AssessmentLearnController::class, 'result']);
            Route::get('courses/{course}/completion', [CompletionController::class, 'show'])->middleware('throttle:60,1,r20');
            Route::get('certificates', [CompletionController::class, 'certificates']);
            Route::get('certificates/{certificate}/pdf', [CompletionController::class, 'pdf'])->middleware('throttle:30,1,r21');
            Route::get('resources/{resource}/download', [LearnController::class, 'downloadResource'])->middleware('throttle:60,1,r22');
        });

        // Lecturer / course-staff workspace (object-level rules in CoursePolicy)
        Route::prefix('manage')->middleware(['mfa', 'permission:courses.manage-own,courses.manage-all'])->group(function () {
            Route::get('courses', [Manage\CourseController::class, 'index']);
            Route::post('courses', [Manage\CourseController::class, 'store']);
            Route::get('courses/{course}', [Manage\CourseController::class, 'show']);
            Route::put('courses/{course}', [Manage\CourseController::class, 'update']);
            Route::delete('courses/{course}', [Manage\CourseController::class, 'destroy']);
            Route::put('courses/{course}/pricing', [Manage\CourseController::class, 'pricing']);
            Route::put('courses/{course}/prerequisites', [Manage\CourseController::class, 'prerequisites']);
            Route::get('courses/{course}/versions', [Manage\CourseController::class, 'versions']);
            Route::post('courses/{course}/submit', [Manage\CourseController::class, 'submit']);
            Route::post('courses/{course}/approve', [Manage\CourseController::class, 'approve']);
            Route::post('courses/{course}/reject', [Manage\CourseController::class, 'reject']);
            Route::post('courses/{course}/publish', [Manage\CourseController::class, 'publish']);
            Route::post('courses/{course}/archive', [Manage\CourseController::class, 'archive']);
            Route::post('courses/{course}/restore', [Manage\CourseController::class, 'restore']);

            Route::post('courses/{course}/modules', [Manage\CurriculumController::class, 'storeModule']);
            Route::put('courses/{course}/modules/order', [Manage\CurriculumController::class, 'reorderModules']);
            Route::put('modules/{module}', [Manage\CurriculumController::class, 'updateModule']);
            Route::delete('modules/{module}', [Manage\CurriculumController::class, 'destroyModule']);
            Route::post('modules/{module}/lessons', [Manage\CurriculumController::class, 'storeLesson']);
            Route::put('modules/{module}/lessons/order', [Manage\CurriculumController::class, 'reorderLessons']);
            Route::put('lessons/{lesson}', [Manage\CurriculumController::class, 'updateLesson']);
            Route::delete('lessons/{lesson}', [Manage\CurriculumController::class, 'destroyLesson']);
            Route::get('courses/{course}/assignments', [Manage\AssignmentController::class, 'index']);
            Route::post('courses/{course}/assignments', [Manage\AssignmentController::class, 'store']);
            Route::get('courses/{course}/rubrics', [Manage\AssignmentController::class, 'rubrics']);
            Route::post('courses/{course}/rubrics', [Manage\AssignmentController::class, 'storeRubric']);
            Route::get('assignments/{assignment}', [Manage\AssignmentController::class, 'show']);
            Route::put('assignments/{assignment}', [Manage\AssignmentController::class, 'update']);
            Route::post('assignments/{assignment}/publish', [Manage\AssignmentController::class, 'publish']);
            Route::get('assignments/{assignment}/submissions', [Manage\AssignmentController::class, 'submissions']);
            Route::get('submissions/{submission}', [Manage\AssignmentController::class, 'submission']);
            Route::post('submissions/{submission}/grade', [Manage\AssignmentController::class, 'grade'])->middleware('throttle:120,1,r23');
            Route::post('submissions/{submission}/return', [Manage\AssignmentController::class, 'returnForRevision']);
            Route::get('submission-files/{file}', [Manage\AssignmentController::class, 'downloadFile'])->middleware('throttle:120,1,r24');
            Route::get('courses/{course}/question-banks', [Manage\AssessmentController::class, 'banks']);
            Route::post('courses/{course}/question-banks', [Manage\AssessmentController::class, 'storeBank']);
            Route::get('question-banks/{bank}/questions', [Manage\AssessmentController::class, 'questions']);
            Route::post('question-banks/{bank}/questions', [Manage\AssessmentController::class, 'storeQuestion']);
            Route::put('questions/{question}', [Manage\AssessmentController::class, 'updateQuestion']);
            Route::delete('questions/{question}', [Manage\AssessmentController::class, 'archiveQuestion']);
            Route::get('courses/{course}/assessments', [Manage\AssessmentController::class, 'index']);
            Route::post('courses/{course}/assessments', [Manage\AssessmentController::class, 'store']);
            Route::get('assessments/{assessment}', [Manage\AssessmentController::class, 'show']);
            Route::put('assessments/{assessment}', [Manage\AssessmentController::class, 'update']);
            Route::post('assessments/{assessment}/publish', [Manage\AssessmentController::class, 'publish']);
            Route::post('assessments/{assessment}/publish-results', [Manage\AssessmentController::class, 'publishResults']);
            Route::post('assessments/{assessment}/grant-attempts', [Manage\AssessmentController::class, 'grantAttempts']);
            Route::get('assessments/{assessment}/attempts', [Manage\AssessmentController::class, 'attempts']);
            Route::get('attempts/{attempt}', [Manage\AssessmentController::class, 'attempt']);
            Route::put('attempts/{attempt}/responses/{questionId}/mark', [Manage\AssessmentController::class, 'markResponse'])->middleware('throttle:300,1,r25');
            Route::get('courses/{course}/completion-policy', [Manage\CompletionController::class, 'policy']);
            Route::put('courses/{course}/completion-policy', [Manage\CompletionController::class, 'updatePolicy']);
            Route::get('courses/{course}/completion-report', [Manage\CompletionController::class, 'report']);
            Route::get('courses/{course}/students/{user}/completion', [Manage\CompletionController::class, 'student']);
            Route::post('courses/{course}/students/{user}/approve-completion', [Manage\CompletionController::class, 'approve']);
            Route::post('lessons/{lesson}/video/upload', [Manage\VideoController::class, 'authoriseUpload'])->middleware('throttle:20,1,r26');
            Route::get('video-assets/{asset}', [Manage\VideoController::class, 'status']);
            Route::post('lessons/{lesson}/resources', [Manage\CurriculumController::class, 'uploadResource'])->middleware('throttle:30,1,r27');
            Route::delete('resources/{resource}', [Manage\CurriculumController::class, 'destroyResource']);
        });

        // Administration: MFA enforced for privileged roles, then permission-gated.
        Route::prefix('admin')->middleware('mfa')->group(function () {
            Route::middleware('permission:users.view')->group(function () {
                Route::get('users', [Admin\UserController::class, 'index']);
                Route::get('users/{user}', [Admin\UserController::class, 'show']);
            });
            Route::middleware('permission:users.manage')->group(function () {
                Route::post('users', [Admin\UserController::class, 'store']);
                Route::put('users/{user}/roles', [Admin\UserController::class, 'updateRoles']);
                Route::post('users/{user}/suspend', [Admin\UserController::class, 'suspend']);
                Route::post('users/{user}/reactivate', [Admin\UserController::class, 'reactivate']);
            });
            Route::prefix('finance')->group(function () {
                Route::middleware('permission:finance.view')->group(function () {
                    Route::get('orders', [Admin\FinanceController::class, 'orders']);
                    Route::get('payments', [Admin\FinanceController::class, 'payments']);
                    Route::get('payments/{payment}', [Admin\FinanceController::class, 'payment']);
                    Route::get('webhooks', [Admin\FinanceController::class, 'webhooks']);
                    Route::get('exceptions', [Admin\FinanceController::class, 'exceptions']);
                    Route::get('refunds', [Admin\FinanceController::class, 'refunds']);
                });
                Route::middleware('permission:reports.finance')->group(function () {
                    Route::get('summary', [Admin\FinanceController::class, 'summary']);
                    Route::get('export', [Admin\FinanceController::class, 'export'])->middleware('throttle:10,1,r28');
                });
                Route::post('payments/{payment}/reconcile', [Admin\FinanceController::class, 'reconcile'])->middleware(['permission:finance.reconcile', 'throttle:30,1,r29']);
                Route::post('payments/{payment}/refunds', [Admin\FinanceController::class, 'refund'])->middleware(['permission:finance.refund', 'throttle:10,1,r30']);
                Route::middleware('permission:coupons.manage')->group(function () {
                    Route::get('coupons', [Admin\FinanceController::class, 'coupons']);
                    Route::post('coupons', [Admin\FinanceController::class, 'storeCoupon']);
                    Route::put('coupons/{coupon}', [Admin\FinanceController::class, 'updateCoupon']);
                });
            });
            Route::middleware('permission:certificates.manage')->group(function () {
                Route::get('certificates', [Admin\CertificateController::class, 'index']);
                Route::post('certificates/{certificate}/revoke', [Admin\CertificateController::class, 'revoke'])->middleware('throttle:20,1,r31');
                Route::post('certificates/{certificate}/reissue', [Admin\CertificateController::class, 'reissue'])->middleware('throttle:20,1,r32');
            });
            Route::middleware('permission:categories.manage')->group(function () {
                Route::get('categories', [Admin\CategoryController::class, 'index']);
                Route::post('categories', [Admin\CategoryController::class, 'store']);
                Route::put('categories/{category}', [Admin\CategoryController::class, 'update']);
                Route::delete('categories/{category}', [Admin\CategoryController::class, 'destroy']);
            });
            Route::middleware('permission:enrolments.manage')->group(function () {
                Route::get('enrolments', [Admin\EnrolmentController::class, 'index']);
                Route::post('enrolments', [Admin\EnrolmentController::class, 'store']);
                Route::put('enrolments/{enrollment}', [Admin\EnrolmentController::class, 'update']);
            });
            Route::middleware('permission:roles.manage')->group(function () {
                Route::get('roles', [Admin\RoleController::class, 'index']);
                Route::put('roles/{role}/permissions', [Admin\RoleController::class, 'syncPermissions']);
            });
        });
    });
});
