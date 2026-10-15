<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Manage\VideoController;
use App\Http\Controllers\Controller;
use App\Models\VideoAsset;
use App\Services\Video\VideoProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoWebhookController extends Controller
{
    public function cloudflare(Request $request, VideoProvider $video, VideoController $apply): JsonResponse
    {
        if (! $video->verifyWebhook($request->getContent(), $request->header('Webhook-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $p = json_decode($request->getContent(), true) ?: [];
        if ($asset = VideoAsset::where('provider_video_id', $p['uid'] ?? '')->first()) {
            $state = $p['status']['state'] ?? 'unknown';
            $apply->apply($asset, ['state' => $state, 'ready' => (bool) ($p['readyToStream'] ?? false), 'duration_seconds' => ($p['duration'] ?? 0) > 0 ? (int) ceil($p['duration']) : null,
                'error' => $state === 'error' ? ($p['status']['errorReasonText'] ?? 'processing_failed') : null]);
        }

        return response()->json(['received' => true]);
    }
}
