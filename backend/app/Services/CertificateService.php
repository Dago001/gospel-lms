<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Certificate;
use App\Models\CertificateRevocation;
use App\Models\Course;
use App\Models\CourseCompletionCheck;
use App\Models\User;
use App\Notifications\CertificateIssuedNotice;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CertificateService
{
    public function __construct(private AuditLogger $audit) {}

    /** Called only from the completion engine, after it has confirmed eligibility. Idempotent. */
    public function issueIfEligible(User $user, Course $course, CourseCompletionCheck $check): ?Certificate
    {
        if (! $check->eligible) {
            return null;
        }
        if ($existing = Certificate::where(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'active'])->first()) {
            return $existing;
        }
        $blocked = CertificateRevocation::whereIn('certificate_id', Certificate::where(['user_id' => $user->id, 'course_id' => $course->id])->select('id'))->where('block_reissue', true)->exists();
        if ($blocked) {
            return null;                                           // an administrator revoked this and said do not reissue automatically
        }

        return $this->create($user, $course, $check->grade_percent, null);
    }

    private function create(User $user, Course $course, ?float $grade, ?User $by): Certificate
    {
        $policy = array_merge(CompletionEngine::DEFAULT_POLICY, $course->completion_policy ?? []);
        try {
            $cert = DB::transaction(fn () => Certificate::create([
                'number' => $this->uniqueNumber(), 'verification_code' => Str::lower(Str::random(32)), 'user_id' => $user->id, 'course_id' => $course->id, 'status' => 'active',
                'holder_name' => $user->name, 'template_path' => CertificateTemplate::current()['path'], 'layout' => CertificateTemplate::current()['path'] ? CertificateTemplate::current()['layout'] : null, 'course_title' => $course->title, 'duration_minutes' => $course->durationMinutes() ?: null, 'grade_percent' => $grade,
                'show_grade' => (bool) $policy['show_grade_on_certificate'], 'signatory_name' => Settings::academy()['signatory_name'], 'signatory_title' => Settings::academy()['signatory_title'], 'issued_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return Certificate::where(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'active'])->firstOrFail();   // lost a race: the winner's certificate is the certificate
        }
        $this->audit->log('certificate.issued', $cert, new: ['number' => $cert->number, 'course_id' => $course->id], actorId: $by?->id ?? $user->id);
        DB::afterCommit(fn () => $user->notify(new CertificateIssuedNotice($course->title, $cert->number)));

        return $cert;
    }

    private function uniqueNumber(): string
    {
        do {
            $n = 'GLC-'.now()->format('Y').'-'.Str::upper(Str::random(8));
        } while (Certificate::where('number', $n)->exists());

        return $n;
    }

    public function verifyUrl(Certificate $c): string
    {
        return rtrim(config('app.frontend_url'), '/').'/verify/'.$c->verification_code;
    }

    public function revoke(Certificate $c, User $by, string $reason, bool $blockReissue = true): Certificate
    {
        return DB::transaction(function () use ($c, $by, $reason, $blockReissue) {
            $c = Certificate::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if ($c->status === 'revoked') {
                throw new DomainException('This certificate is already revoked.', 'already_revoked', 409);
            }
            $c->update(['status' => 'revoked']);
            CertificateRevocation::create(['certificate_id' => $c->id, 'revoked_by' => $by->id, 'reason' => $reason, 'block_reissue' => $blockReissue, 'revoked_at' => now()]);
            $this->audit->log('certificate.revoked', $c, ['status' => 'active'], ['status' => 'revoked'], $reason, $by->id);

            return $c;
        });
    }

    /** Administrator override after a revocation (e.g. revoked in error). Needs a reason; leaves the revoked record intact. */
    public function reissue(Certificate $revoked, User $by, string $reason): Certificate
    {
        if ($revoked->status !== 'revoked') {
            throw new DomainException('Only a revoked certificate can be reissued.', 'not_revoked', 409);
        }
        $user = User::findOrFail($revoked->user_id);
        $course = Course::withTrashed()->findOrFail($revoked->course_id);
        $new = $this->create($user, $course, $revoked->grade_percent, $by);
        $this->audit->log('certificate.reissued', $new, new: ['replaces' => $revoked->id], reason: $reason, actorId: $by->id);

        return $new;
    }

    public function pdf(Certificate $c): string
    {
        $url = $this->verifyUrl($c);
        $qr = (new Builder(writer: new PngWriter, data: $url, size: 220, margin: 6))->build()->getDataUri();
        $disk = \Illuminate\Support\Facades\Storage::disk('private');
        if ($c->template_path && $disk->exists($c->template_path)) {
            return $this->templatePdf($c->template_path, $c->layout ?? [], [
                'holder_name' => $c->holder_name, 'course_title' => $c->course_title, 'issued_on' => 'Issued on '.$c->issued_at->timezone('Africa/Lagos')->format('j F Y'),
                'grade' => $c->show_grade && $c->grade_percent !== null ? 'Final grade '.number_format($c->grade_percent, 1).'%' : '',
                'duration' => $c->duration_minutes ? 'Course duration '.intdiv($c->duration_minutes, 60).'h '.($c->duration_minutes % 60).'m' : '',
                'signatory' => trim(($c->signatory_name ?: 'Authorised signatory')."\n".($c->signatory_title ?? '')),
                'number' => 'Certificate no. '.$c->number, 'verify_url' => 'Verify at '.$url,
            ], $qr, $c->number);
        }

        return Pdf::loadView('pdf.certificate', ['c' => $c, 'qr' => $qr, 'url' => $url, 'academy' => Settings::academy()])->setPaper('a4', 'landscape')->output();
    }

    /** Renders certificate artwork with the given details printed on it. Used for issued certificates and for the admin preview. */
    public function templatePdf(string $path, array $layout, array $text, string $qr, string $number): string
    {
        $layout = CertificateTemplate::layout($layout);
        $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';
        $bg = 'data:'.$mime.';base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('private')->get($path));

        return Pdf::loadView('pdf.certificate-template', ['bg' => $bg, 'w' => CertificateTemplate::PAGE_W, 'h' => CertificateTemplate::PAGE_H, 'fields' => CertificateTemplate::placed($layout, $text),
            'qrBox' => CertificateTemplate::qrBox($layout), 'qr' => $qr, 'number' => $number])->setPaper('a4', 'landscape')->output();
    }

    /** Admin preview with obviously fictional details, using the saved layout. */
    public function previewPdf(string $path, array $layout): string
    {
        $qr = (new Builder(writer: new PngWriter, data: url('/verify/sample'), size: 220, margin: 6))->build()->getDataUri();

        return $this->templatePdf($path, $layout, [
            'holder_name' => 'Sample Student Name', 'course_title' => 'Sample Course Title', 'issued_on' => 'Issued on '.now('Africa/Lagos')->format('j F Y'), 'grade' => 'Final grade 85.0%',
            'duration' => 'Course duration 12h 30m', 'signatory' => "Authorised Signatory\nRegistrar", 'number' => 'Certificate no. GL-SAMPLE-0001', 'verify_url' => 'Verify at '.url('/verify/sample'),
        ], $qr, 'SAMPLE');
    }
}
