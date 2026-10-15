<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(\App\Services\CourseWorkflow::class)->publishDue())->name('courses:publish-due')->everyMinute()->withoutOverlapping();

Schedule::command('payments:reconcile')->everyTenMinutes()->withoutOverlapping()->onOneServer();

Schedule::call(fn () => app(\App\Services\AssessmentService::class)->expireDue())->name('assessments:expire-attempts')->everyMinute()->withoutOverlapping();

Schedule::command('reminders:send')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::call(function () {
    \App\Models\LiveSession::where('provider', 'zoom')->where('status', '!=', 'cancelled')->whereNull('attendance_synced_at')->where('starts_at', '<', now()->subHour())->where('starts_at', '>', now()->subDays(3))
        ->each(fn ($s) => \App\Jobs\SyncZoomAttendance::dispatch($s->id));      // safety net if the meeting.ended webhook never arrived
})->name('live:sync-attendance')->hourly()->onOneServer();

Schedule::call(fn () => \Illuminate\Support\Facades\Cache::put('scheduler.heartbeat', now()->timestamp, 900))->name('scheduler:heartbeat')->everyMinute();
Schedule::command('gospel:backup')->dailyAt('02:30')->timezone('Africa/Lagos')->onOneServer()->withoutOverlapping();
Schedule::command('gospel:backup-verify')->weeklyOn(0, '04:00')->timezone('Africa/Lagos')->onOneServer()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('auth:clear-resets')->daily();
Schedule::call(function () {
    \App\Models\NotificationLog::where('created_at', '<', now()->subDays(90))->delete();                                   // retention: delivery log 90 days
    \App\Models\DataExportRequest::where('expires_at', '<', now())->each(function ($r) {
        $r->file_path && \Illuminate\Support\Facades\Storage::disk('private')->delete($r->file_path);
        $r->update(['status' => 'expired', 'file_path' => null]);
    });
})->name('retention:purge')->dailyAt('03:15');
