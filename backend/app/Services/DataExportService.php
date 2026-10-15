<?php

namespace App\Services;

use App\Models\DataExportRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Right of access: a machine-readable copy of the personal data we hold about one user. Excludes other people's data and gateway internals. */
class DataExportService
{
    public function build(User $u): array
    {
        $own = fn (string $table, string $col = 'user_id') => DB::table($table)->where($col, $u->id);

        return [
            'generated_at' => now()->toIso8601String(),
            'account' => $u->only(['id', 'name', 'email', 'status', 'email_verified_at', 'created_at', 'last_login_at']) + ['roles' => $u->roleSlugs()],
            'profile' => $u->profile?->only(['phone', 'bio', 'country', 'timezone', 'headline']),
            'consents' => $own('consent_records')->get(['type', 'version', 'granted_at', 'withdrawn_at']),
            'enrolments' => $own('course_enrollments')->join('courses', 'courses.id', '=', 'course_enrollments.course_id')->get(['courses.title as course', 'course_enrollments.status', 'course_enrollments.enrolled_at', 'course_enrollments.completed_at']),
            'lesson_progress' => $own('lesson_progress')->get(['lesson_id', 'status', 'percent', 'completed_at']),
            'assignment_submissions' => $own('assignment_submissions')->get(['assignment_id', 'attempt', 'text_response', 'status', 'submitted_at', 'is_late']),
            'assignment_grades' => DB::table('submission_grades as g')->join('assignment_submissions as s', 's.id', '=', 'g.submission_id')->where('s.user_id', $u->id)->where('g.status', 'published')->get(['s.assignment_id', 'g.final_score', 'g.feedback', 'g.published_at']),
            'assessment_attempts' => $own('assessment_attempts')->whereNotNull('results_published_at')->get(['assessment_id', 'attempt_no', 'total_score', 'max_score', 'percent', 'passed', 'submitted_at']),
            'orders' => $own('orders')->get(['number', 'status', 'currency', 'total_minor', 'created_at', 'paid_at']),
            'receipts' => $own('receipts')->get(['number', 'issued_at', 'data']),
            'certificates' => $own('certificates')->get(['number', 'course_title', 'status', 'issued_at']),
            'support_tickets' => $own('support_tickets')->get(['number', 'subject', 'status', 'created_at']),
            'forum_posts' => $own('discussion_posts')->whereNull('deleted_at')->get(['thread_id', 'body', 'created_at']),
            'notification_preferences' => $own('notification_preferences')->get(['type', 'email', 'in_app']),
        ];
    }

    public function generate(DataExportRequest $r): void
    {
        try {
            $path = "exports/{$r->user_id}/{$r->id}.json";
            Storage::disk('private')->put($path, json_encode($this->build(User::findOrFail($r->user_id)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $r->update(['status' => 'ready', 'file_path' => $path, 'ready_at' => now(), 'expires_at' => now()->addDays(7)]);
        } catch (\Throwable $e) {
            $r->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 200)]);
            throw $e;
        }
    }
}
