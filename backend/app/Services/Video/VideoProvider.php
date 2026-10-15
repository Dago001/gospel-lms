<?php

namespace App\Services\Video;

interface VideoProvider
{
    /** Start a resumable (TUS) upload. @return array{provider_video_id:string, upload_url:string} */
    public function createUpload(string $title, int $uploadLengthBytes, int $maxDurationSeconds): array;

    /** @return array{state:string, ready:bool, duration_seconds:int|null, error:string|null} */
    public function status(string $providerVideoId): array;

    /** Short-lived signed playback URLs. @return array{iframe_url:string, hls_url:string, expires_at:int} */
    public function playback(string $providerVideoId, int $ttlSeconds): array;

    public function verifyWebhook(string $rawBody, ?string $signatureHeader): bool;

    public function delete(string $providerVideoId): void;
}
