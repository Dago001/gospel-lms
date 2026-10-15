<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(\App\Services\CourseWorkflow::class)->publishDue())->name('courses:publish-due')->everyMinute()->withoutOverlapping();
