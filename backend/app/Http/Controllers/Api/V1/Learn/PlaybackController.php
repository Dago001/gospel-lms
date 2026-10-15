<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\PlaybackSession;
use App\Services\Payments\GatewayException;
use App\Services\Video\ProgressionService;
use App\Services\Video\ProgressTracker;
use App\Services\Video\VideoProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PlaybackController extends Controller
{
    public function __construct(private ProgressTracker $tracker, private ProgressionService $progression, private VideoProvider $video) {}

    private function isStaff($user, Lesson $lesson): bool
    {
        return Gate::forUser($user)->allows('manage', $lesson->course);
    }

    /** Opens a playback session: authorises access, enforces lesson order, returns the source and resume point. */
    public function start(Request $request, Lesson $lesson): JsonResponse
    {
        Gate::authorize('view', $lesson);
        $lesson->load('course');
        $user = $request->user();
        $staff = $this->isStaff($user, $lesson);
        if (! $staff) {
            $this->progression->assertUnlocked($user, $lesson);
        }
        if ($lesson->type !== 'video' || ! $lesson->video_source) {
            throw new DomainException('This lesson has no video.', 'not_a_video', 422);
        }
        $payload = ['lesson_id' => $lesson->id, 'duration_seconds' => $lesson->duration_seconds, 'required_completion_percent' => $lesson->requiredCompletionPercent(), 'source' => $this->source($lesson)];
        if ($staff) {
            return response()->json(['data' => $payload + ['session_id' => null, 'tracked' => false, 'resume_position' => 0]]);   // previews are never recorded as student progress
        }
        $started = $this->tracker->start($user, $lesson, $request);

        return response()->json(['data' => $payload + ['session_id' => $started['session']->id, 'tracked' => true, 'resume_position' => $started['resume_position'], 'progress' => $started['progress'],
            'limits' => $lesson->video_source === 'youtube' ? 'External YouTube playback is verified for plausibility only; the academy cannot control the YouTube player itself.' : null]], 201);
    }

    private function source(Lesson $lesson): array
    {
        if ($lesson->video_source === 'youtube') {
            return ['type' => 'youtube', 'youtube_id' => $lesson->youtube_id];
        }
        $asset = $lesson->videoAsset;
        if (! $asset || $asset->status !== 'ready') {
            throw new DomainException('This video is still being processed.', 'video_not_ready', 409);
        }
        try {
            $ttl = max(($lesson->duration_seconds ?? 0) + 1800, 3600);
            $p = $this->video->playback($asset->provider_video_id, $ttl);
        } catch (GatewayException) {
            throw new DomainException('Video playback is temporarily unavailable.', 'video_unavailable', 503);
        }

        return ['type' => 'hosted', 'iframe_url' => $p['iframe_url'], 'hls_url' => $p['hls_url'], 'expires_at' => $p['expires_at']];
    }

    public function refreshToken(Request $request, PlaybackSession $session): JsonResponse
    {
        $this->ownedSession($request, $session);

        return response()->json(['data' => ['source' => $this->source($session->lesson->load('course', 'videoAsset'))]]);
    }

    public function events(Request $request, PlaybackSession $session): JsonResponse
    {
        $this->ownedSession($request, $session);
        $d = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:'.config('video.max_events_per_batch')],
            'events.*.seq' => ['required', 'integer', 'min:1', 'max:1000000'],
            'events.*.type' => ['required', 'in:play,pause,heartbeat,seek,ended'],
            'events.*.position' => ['required', 'numeric', 'min:0', 'max:86400'],
            'events.*.client_ts_ms' => ['nullable', 'integer', 'min:0'],
        ]);
        $r = $this->tracker->ingest($session, $d['events']);

        return response()->json(['data' => $r]);
    }

    /** Owner-only; access to the lesson must still hold (suspension / cancellation takes effect immediately). */
    private function ownedSession(Request $request, PlaybackSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 404);
        Gate::authorize('view', $session->lesson->load('course'));
    }

    public function complete(Request $request, Lesson $lesson): JsonResponse
    {
        Gate::authorize('view', $lesson);
        $this->progression->assertUnlocked($request->user(), $lesson);
        if ($this->isStaff($request->user(), $lesson)) {
            throw new DomainException('Staff previews are not recorded as progress.', 'staff_preview', 403);
        }
        $newly = $this->tracker->completeReading($request->user(), $lesson);

        return response()->json(['data' => ['completed' => true, 'newly_completed' => $newly]]);
    }

    public function acknowledge(Request $request, LessonResource $resource): JsonResponse
    {
        Gate::authorize('view', $resource->lesson);
        DB::table('resource_acknowledgements')->insertOrIgnore(['user_id' => $request->user()->id, 'resource_id' => $resource->id, 'acknowledged_at' => now()]);

        return response()->json(['data' => ['acknowledged' => true]]);
    }

    public function progress(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('learn', $course);

        return response()->json(['data' => $this->progression->summary($request->user(), $course)]);
    }
}
