<?php

namespace App\Http\Controllers\Api\V1\Manage;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\VideoAsset;
use App\Services\AuditLogger;
use App\Services\Payments\GatewayException;
use App\Services\Video\VideoProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VideoController extends Controller
{
    public function __construct(private VideoProvider $video) {}

    /** Authorises a resumable upload straight from the lecturer's browser to the video host. The file never touches our servers. */
    public function authoriseUpload(Request $request, Lesson $lesson, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('edit', $lesson->course);
        $d = $request->validate(['size_bytes' => ['required', 'integer', 'min:1', 'max:'.(8 * 1024 ** 3)], 'filename' => ['nullable', 'string', 'max:200']]);
        try {
            $up = $this->video->createUpload($lesson->title, $d['size_bytes'], 6 * 3600);
        } catch (GatewayException $e) {
            throw new DomainException('The video service is unavailable. Try again shortly.', 'video_unavailable', 503);
        }
        $asset = VideoAsset::create(['provider' => config('video.provider'), 'provider_video_id' => $up['provider_video_id'], 'title' => $lesson->title, 'status' => 'uploading',
            'course_id' => $lesson->course_id, 'uploaded_by' => $request->user()->id]);
        $lesson->forceFill(['type' => 'video', 'video_source' => 'hosted', 'video_asset_id' => $asset->id, 'youtube_id' => null, 'duration_seconds' => null])->save();
        $audit->log('video.upload_authorised', $asset, new: ['lesson_id' => $lesson->id]);

        return response()->json(['data' => ['asset_id' => $asset->id, 'upload_url' => $up['upload_url'], 'protocol' => 'tus']], 201);
    }

    /** Poll after upload; also applies the result to the lesson (duration) once the host reports ready. */
    public function status(VideoAsset $asset): JsonResponse
    {
        $lesson = Lesson::where('video_asset_id', $asset->id)->with('course')->firstOrFail();
        Gate::authorize('manage', $lesson->course);
        if (in_array($asset->status, ['uploading', 'processing'], true)) {
            try {
                $this->apply($asset, $this->video->status($asset->provider_video_id));
            } catch (GatewayException) {
                // keep last known state; the webhook will also update it
            }
        }

        return response()->json(['data' => ['id' => $asset->id, 'status' => $asset->status, 'duration_seconds' => $asset->duration_seconds, 'error' => $asset->error]]);
    }

    public function apply(VideoAsset $asset, array $s): void
    {
        if ($s['error']) {
            $asset->update(['status' => 'failed', 'error' => $s['error']]);

            return;
        }
        if ($s['ready'] && $s['duration_seconds']) {
            $asset->update(['status' => 'ready', 'duration_seconds' => $s['duration_seconds'], 'error' => null]);
            Lesson::where('video_asset_id', $asset->id)->update(['duration_seconds' => $s['duration_seconds']]);   // duration is detected, never typed in, for hosted video
        } elseif ($asset->status === 'uploading' && $s['state'] !== 'pendingupload') {
            $asset->update(['status' => 'processing']);
        }
    }
}
