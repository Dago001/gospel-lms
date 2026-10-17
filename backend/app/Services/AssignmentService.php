<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\SubmissionFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssignmentService
{
    /** Extension => MIME types libmagic may report for genuine files of that kind. Content is sniffed from bytes, never from the client. */
    private const CONTENT_MATCH = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'zip' => ['application/zip'],
    ];

    public function __construct(private EnrolmentService $enrolments, private MalwareScanner $scanner, private AuditLogger $audit) {}

    /** @param UploadedFile[] $files */
    public function submit(User $user, Assignment $a, ?string $text, array $files): AssignmentSubmission
    {
        if ($a->status !== 'published') {
            throw new DomainException('This assignment is not available.', 'assignment_unavailable', 404);
        }
        if (! $this->enrolments->userHasAccess($user, $a->course)) {
            throw new DomainException('You are not enrolled in this course.', 'not_enrolled', 403);
        }
        if (blank($text) && ! $files) {
            throw new DomainException('Provide a written response or attach a file.', 'empty_submission');
        }
        if (count($files) > $a->max_files) {
            throw new DomainException("You can attach at most {$a->max_files} files.", 'too_many_files');
        }
        $this->validateFiles($a, $files);

        $now = now();
        if ($a->opens_at && $a->opens_at->gt($now)) {
            throw new DomainException('This assignment has not opened yet.', 'not_open', 409);
        }
        $late = false;
        $lateMinutes = 0;
        if ($a->due_at && $now->gt($a->due_at)) {
            $withinCutoff = ! $a->late_cutoff_at || $now->lte($a->late_cutoff_at);
            if ($a->late_policy !== 'penalty' || ! $withinCutoff) {
                throw new DomainException('The deadline has passed.', 'deadline_passed', 422);
            }
            $late = true;
            $lateMinutes = (int) $a->due_at->diffInMinutes($now);
        }

        return DB::transaction(function () use ($user, $a, $text, $files, $now, $late, $lateMinutes) {
            \App\Support\DbCompat::lockFor($a->id.$user->id, $user->id);   // serialise this learner's submissions to this assignment
            $last = AssignmentSubmission::where(['assignment_id' => $a->id, 'user_id' => $user->id])->orderByDesc('attempt')->first();
            if ($last) {
                if (! $a->allow_resubmission && $last->status !== 'returned') {
                    throw new DomainException('Resubmission is not allowed for this assignment.', 'resubmission_not_allowed', 409);
                }
                if ($last->attempt >= $a->max_attempts) {
                    throw new DomainException('You have used all attempts.', 'attempt_limit_reached', 409);
                }
                if ($last->status === 'graded' && $last->grade?->status === 'published') {
                    throw new DomainException('This submission has already been graded.', 'already_graded', 409);
                }
            }
            $sub = AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $user->id, 'attempt' => ($last->attempt ?? 0) + 1, 'text_response' => $text,
                'status' => 'submitted', 'submitted_at' => $now, 'is_late' => $late, 'late_minutes' => $lateMinutes]);
            foreach ($files as $f) {
                $this->scanner->assertClean($f);
                $path = $f->storeAs("submissions/{$a->id}/{$user->id}", Str::uuid().'.'.strtolower($f->getClientOriginalExtension()), 'private');
                SubmissionFile::create(['submission_id' => $sub->id, 'path' => $path, 'original_name' => Str::limit(preg_replace('/[^\w.\- ]+/u', '_', $f->getClientOriginalName()), 190, ''),
                    'mime' => $f->getMimeType(), 'size_bytes' => $f->getSize(), 'sha256' => hash_file('sha256', $f->getRealPath())]);
            }
            $this->audit->log('submission.created', $sub, new: ['assignment_id' => $a->id, 'attempt' => $sub->attempt, 'late' => $late], actorId: $user->id);

            return $sub;
        });
    }

    /** @param UploadedFile[] $files */
    private function validateFiles(Assignment $a, array $files): void
    {
        $allowed = array_map('strtolower', $a->allowed_extensions);
        foreach ($files as $f) {
            $ext = strtolower($f->getClientOriginalExtension());
            if (! in_array($ext, $allowed, true) || ! isset(self::CONTENT_MATCH[$ext])) {
                throw new DomainException("Files of type .$ext are not accepted for this assignment.", 'file_type_not_allowed');
            }
            if (! in_array((new \finfo(FILEINFO_MIME_TYPE))->file($f->getRealPath()), self::CONTENT_MATCH[$ext], true)) {
                throw new DomainException('A file\'s content does not match its extension.', 'file_content_mismatch');
            }
            if ($f->getSize() > $a->max_file_mb * 1024 * 1024) {
                throw new DomainException("Each file must be under {$a->max_file_mb} MB.", 'file_too_large');
            }
        }
    }

    public function deleteFiles(AssignmentSubmission $s): void
    {
        foreach ($s->files as $f) {
            Storage::disk('private')->delete($f->path);
        }
    }
}
