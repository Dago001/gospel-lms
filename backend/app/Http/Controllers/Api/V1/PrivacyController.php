<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateDataExport;
use App\Models\ConsentRecord;
use App\Models\DataExportRequest;
use App\Services\AuditLogger;
use App\Services\PrivateFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrivacyController extends Controller
{
    public function consents(Request $request): JsonResponse
    {
        return response()->json(['data' => ConsentRecord::where('user_id', $request->user()->id)->orderBy('granted_at')->get(['type', 'version', 'granted_at', 'withdrawn_at'])]);
    }

    public function requestExport(Request $request, AuditLogger $audit): JsonResponse
    {
        $u = $request->user();
        if (DataExportRequest::where('user_id', $u->id)->where('requested_at', '>', now()->subDay())->exists()) {
            throw new DomainException('You can request one export per day.', 'export_rate_limited', 429);
        }
        $r = DataExportRequest::create(['user_id' => $u->id, 'status' => 'requested', 'requested_at' => now()]);
        $audit->log('privacy.export_requested', $r);
        GenerateDataExport::dispatch($r->id);

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->fresh()->status]], 202);
    }

    public function exports(Request $request): JsonResponse
    {
        return response()->json(['data' => DataExportRequest::where('user_id', $request->user()->id)->latest('requested_at')->limit(10)->get()
            ->map(fn ($r) => ['id' => $r->id, 'status' => $r->status, 'requested_at' => $r->requested_at->toIso8601String(), 'expires_at' => $r->expires_at?->toIso8601String()])]);
    }

    public function download(Request $request, DataExportRequest $export)
    {
        abort_unless($export->user_id === $request->user()->id && $export->status === 'ready' && $export->expires_at?->isFuture(), 404);

        return PrivateFile::send($export->file_path, 'my-gospel-lms-data.json');
    }
}
