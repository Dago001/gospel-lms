<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Services\Video\VideoProvider::class, \App\Services\Video\CloudflareStreamProvider::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationSending::class, \App\Listeners\ApplyNotificationPreferences::class);
        \Illuminate\Support\Facades\Event::listen([\Illuminate\Notifications\Events\NotificationSent::class, \Illuminate\Notifications\Events\NotificationFailed::class], \App\Listeners\LogNotificationDelivery::class);
        // Explicit registration: completion must never depend on listener auto-discovery.
        \Illuminate\Support\Facades\Event::listen([\App\Events\LessonCompleted::class, \App\Events\GradePublished::class], \App\Listeners\ReevaluateCompletion::class);

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(8)->mixedCase()->numbers()->uncompromised()
            : Password::min(8)->mixedCase()->numbers());

        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()));

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(30)->by($r->ip()),
        ]);
        RateLimiter::for('chat', fn (Request $r) => [Limit::perMinute(15)->by('chat|'.($r->user('sanctum')?->id ?? $r->ip())), Limit::perHour(200)->by('chath|'.$r->ip())]);
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(10)->by($r->ip()));
        RateLimiter::for('mfa', fn (Request $r) => Limit::perMinute(5)->by(($r->user()?->id ?? $r->session()->getId()).'|'.$r->ip()));
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('password-change', fn (Request $r) => Limit::perMinute(5)->by($r->user()?->id ?? $r->ip()));
    }
}
