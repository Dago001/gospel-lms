<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AuthController;
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
            Route::middleware('permission:roles.manage')->group(function () {
                Route::get('roles', [Admin\RoleController::class, 'index']);
                Route::put('roles/{role}/permissions', [Admin\RoleController::class, 'syncPermissions']);
            });
        });
    });
});
