<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Learn\LearnController;
use App\Http\Controllers\Api\V1\Manage;
use App\Http\Controllers\Api\V1\Public\CatalogueController;
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
        Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    });

    // Public catalogue (published content only)
    Route::prefix('public')->middleware('throttle:120,1')->group(function () {
        Route::get('categories', [CatalogueController::class, 'categories']);
        Route::get('courses', [CatalogueController::class, 'courses']);
        Route::get('courses/{slug}', [CatalogueController::class, 'course']);
        Route::get('lecturers', [CatalogueController::class, 'lecturers']);
        Route::get('lecturers/{id}', [CatalogueController::class, 'lecturer']);
    });

    // Authenticated (session) endpoints
    Route::middleware(['auth:sanctum', 'active.session'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:6,1');

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

        // Learning (students and staff previewing)
        Route::prefix('learn')->middleware('mfa')->group(function () {
            Route::get('courses', [LearnController::class, 'myCourses']);
            Route::post('courses/{course}/enrol', [LearnController::class, 'enrolFree'])->middleware('throttle:10,1');
            Route::get('courses/{course}', [LearnController::class, 'course']);
            Route::get('lessons/{lesson}', [LearnController::class, 'lesson']);
            Route::get('resources/{resource}/download', [LearnController::class, 'downloadResource'])->middleware('throttle:60,1');
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
            Route::post('lessons/{lesson}/resources', [Manage\CurriculumController::class, 'uploadResource'])->middleware('throttle:30,1');
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
