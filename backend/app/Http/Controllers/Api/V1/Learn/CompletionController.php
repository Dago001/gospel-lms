<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Course;
use App\Services\CertificateService;
use App\Services\CompletionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompletionController extends Controller
{
    /** "Why haven't I qualified yet?" Re-evaluates now so the answer is never stale. */
    public function show(Request $request, Course $course, CompletionEngine $engine): JsonResponse
    {
        Gate::authorize('learn', $course);
        $check = $engine->run($request->user(), $course, 'learner_request');

        return response()->json(['data' => ['eligible' => $check->eligible, 'grade_percent' => $check->grade_percent, 'course_pass_percent' => $engine->policy($course)['course_pass_percent'],
            'reasons' => $check->reasons, 'components' => $check->components, 'evaluated_at' => $check->evaluated_at->toIso8601String()]]);
    }

    public function certificates(Request $request): JsonResponse
    {
        return response()->json(['data' => Certificate::where('user_id', $request->user()->id)->latest('issued_at')->get()->map(fn ($c) => [
            'id' => $c->id, 'number' => $c->number, 'course_title' => $c->course_title, 'status' => $c->status, 'issued_at' => $c->issued_at->toIso8601String(),
            'verification_code' => $c->verification_code])]);
    }

    public function pdf(Request $request, Certificate $certificate, CertificateService $svc)
    {
        abort_unless($certificate->user_id === $request->user()->id, 404);
        if ($certificate->status !== 'active') {
            return response()->json(['message' => 'This certificate has been revoked.', 'code' => 'certificate_revoked'], 410);
        }

        return response($svc->pdf($certificate), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$certificate->number.'.pdf"']);
    }
}
