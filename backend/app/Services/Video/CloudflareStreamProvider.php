<?php

namespace App\Services\Video;

use App\Services\Payments\GatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CloudflareStreamProvider implements VideoProvider
{
    private function cfg(string $k): ?string
    {
        return config("services.cloudflare_stream.$k");
    }

    private function api()
    {
        if (! $this->cfg('account_id') || ! $this->cfg('api_token')) {
            throw new GatewayException('Video hosting is not configured.', false);
        }

        return Http::baseUrl(rtrim($this->cfg('base_url'), '/').'/accounts/'.$this->cfg('account_id'))->withToken($this->cfg('api_token'))->acceptJson()->timeout(20)->connectTimeout(5);
    }

    public function createUpload(string $title, int $uploadLengthBytes, int $maxDurationSeconds): array
    {
        try {
            $res = $this->api()->withHeaders([
                'Tus-Resumable' => '1.0.0', 'Upload-Length' => (string) $uploadLengthBytes,
                'Upload-Metadata' => 'name '.base64_encode($title).',requiresignedurls,maxDurationSeconds '.base64_encode((string) $maxDurationSeconds),
            ])->send('POST', '/stream?direct_user=true');
        } catch (ConnectionException) {
            throw new GatewayException('Video service unreachable.', true);
        }
        $id = $res->header('stream-media-id');
        $loc = $res->header('Location');
        if (! $res->successful() || ! $id || ! $loc) {
            throw new GatewayException('Video service rejected the upload request.', $res->serverError(), $res->status());
        }

        return ['provider_video_id' => $id, 'upload_url' => $loc];
    }

    public function status(string $providerVideoId): array
    {
        try {
            $res = $this->api()->get('/stream/'.rawurlencode($providerVideoId));
        } catch (ConnectionException) {
            throw new GatewayException('Video service unreachable.', true);
        }
        if ($res->serverError()) {
            throw new GatewayException('Video service error.', true, $res->status());
        }
        if (! $res->successful() || $res->json('success') !== true) {
            throw new GatewayException('Video not found.', false, $res->status());
        }
        $r = $res->json('result');
        $state = $r['status']['state'] ?? 'unknown';

        return ['state' => $state, 'ready' => (bool) ($r['readyToStream'] ?? false), 'duration_seconds' => isset($r['duration']) && $r['duration'] > 0 ? (int) ceil($r['duration']) : null,
            'error' => $state === 'error' ? ($r['status']['errorReasonText'] ?? 'processing_failed') : null];
    }

    /** Signed locally with the RS256 key from POST /stream/keys: no API call (or rate limit) per viewer. */
    public function playback(string $providerVideoId, int $ttlSeconds): array
    {
        $pem = base64_decode((string) $this->cfg('key_pem_b64'), true);
        $kid = $this->cfg('key_id');
        $code = $this->cfg('customer_code');
        if (! $pem || ! $kid || ! $code) {
            throw new GatewayException('Video playback signing is not configured.', false);
        }
        $ttl = min(max($ttlSeconds, 300), 86400);          // Cloudflare caps token lifetime at 24h
        $now = time();
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'RS256', 'kid' => $kid]));
        $body = $b64(json_encode(['sub' => $providerVideoId, 'kid' => $kid, 'nbf' => $now - 30, 'exp' => $now + $ttl]));
        if (! openssl_sign("$head.$body", $sig, $pem, OPENSSL_ALGO_SHA256)) {
            throw new GatewayException('Could not sign the playback token.', false);
        }
        $token = "$head.$body.".$b64($sig);
        $host = "customer-$code.cloudflarestream.com";

        return ['iframe_url' => "https://$host/$token/iframe", 'hls_url' => "https://$host/$token/manifest/video.m3u8", 'expires_at' => $now + $ttl];
    }

    /** Header format: "time=<unix>,sig1=<hex hmac-sha256 of 'time.body'>". Replay window 5 minutes. */
    public function verifyWebhook(string $rawBody, ?string $header): bool
    {
        $secret = (string) $this->cfg('webhook_secret');
        if ($secret === '' || ! $header || ! preg_match('/time=(\d+),sig1=([a-f0-9]+)/i', $header, $m)) {
            return false;
        }
        if (abs(time() - (int) $m[1]) > 300) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$rawBody, $secret), strtolower($m[2]));
    }

    public function delete(string $providerVideoId): void
    {
        $this->api()->delete('/stream/'.rawurlencode($providerVideoId));
    }
}
