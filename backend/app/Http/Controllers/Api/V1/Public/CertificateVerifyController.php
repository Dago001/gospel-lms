<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Public verification. Discloses the minimum needed to check a certificate: no email, grade, user id or revocation reason. */
class CertificateVerifyController extends Controller
{
    public function show(Request $request, string $code): JsonResponse
    {
        $code = Str::lower(trim($code));
        $cert = preg_match('/^[a-z0-9]{32}$/', $code) ? Certificate::where('verification_code', $code)->first() : null;
        $result = ! $cert ? 'not_found' : ($cert->status === 'active' ? 'valid' : 'revoked');
        DB::table('certificate_verification_logs')->insert(['id' => (string) Str::uuid(), 'certificate_id' => $cert?->id, 'code_prefix' => substr($code, 0, 6), 'result' => $result,
            'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'created_at' => now()]);

        if (! $cert) {
            return response()->json(['data' => ['status' => 'not_found']], 404);
        }

        return response()->json(['data' => ['status' => $result, 'certificate_number' => $cert->number, 'holder_name' => $cert->holder_name, 'course_title' => $cert->course_title,
            'issued_on' => $cert->issued_at->toDateString(), 'issuer' => config('academy.name')]]);
    }
}
