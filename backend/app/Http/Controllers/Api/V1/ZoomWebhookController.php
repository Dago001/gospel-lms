<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncZoomAttendance;
use App\Models\LiveSession;
use App\Models\LiveSessionRecording;
use App\Services\Live\ZoomClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ZoomWebhookController extends Controller
{
    public function handle(Request $request, ZoomClient $zoom): JsonResponse
    {
        $raw = $request->getContent();
        $p = json_decode($raw, true) ?: [];
        $event = $p['event'] ?? '';

        // Zoom's endpoint-ownership handshake is itself signed with the secret token.
        if ($event === 'endpoint.url_validation') {
            $token = $p['payload']['plainToken'] ?? '';

            return response()->json($zoom->validationResponse((string) $token));
        }
        if (! $zoom->verifyWebhook($raw, $request->header('x-zm-request-timestamp'), $request->header('x-zm-signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $obj = $p['payload']['object'] ?? [];
        $session = isset($obj['id']) ? LiveSession::where('provider_meeting_id', (string) $obj['id'])->first() : null;
        if (! $session) {
            return response()->json(['received' => true]);   // not ours (another Zoom app on the same account)
        }

        match ($event) {
            'meeting.started' => $session->status === 'scheduled' ? $session->update(['status' => 'live']) : null,
            'meeting.ended' => $this->ended($session),
            'recording.completed' => $this->recordings($session, $obj['recording_files'] ?? []),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    private function ended(LiveSession $s): void
    {
        if ($s->status !== 'cancelled') {
            $s->update(['status' => 'ended']);
        }
        SyncZoomAttendance::dispatch($s->id)->delay(now()->addMinutes(10));
    }

    private function recordings(LiveSession $s, array $files): void
    {
        foreach ($files as $f) {
            if (($f['file_type'] ?? '') !== 'MP4' || ($f['status'] ?? 'completed') !== 'completed' || empty($f['id']) || empty($f['play_url'])) {
                continue;
            }
            // Stored unpublished: nobody can watch it until the lecturer releases it.
            DB::table('live_session_recordings')->insertOrIgnore(['id' => (string) \Illuminate\Support\Str::uuid(), 'live_session_id' => $s->id, 'provider_recording_id' => (string) $f['id'],
                'play_url' => $f['play_url'], 'duration_seconds' => isset($f['recording_start'], $f['recording_end']) ? max(0, strtotime($f['recording_end']) - strtotime($f['recording_start'])) : null,
                'published' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
