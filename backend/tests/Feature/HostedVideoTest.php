<?php

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\VideoAsset;
use Illuminate\Support\Facades\Http;

function configureCloudflare(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $pub = openssl_pkey_get_details($key)['key'];
    config(['services.cloudflare_stream' => ['account_id' => 'acct123', 'api_token' => 'cf_token', 'customer_code' => 'abcd1234', 'key_id' => 'kid-1',
        'key_pem_b64' => base64_encode($pem), 'webhook_secret' => 'whsec_test', 'base_url' => 'https://api.cloudflare.com/client/v4']]);

    return [$pem, $pub];
}

function b64url_decode(string $s): string
{
    return base64_decode(strtr($s, '-_', '+/'));
}

it('signs short-lived RS256 playback tokens that verify against the public key', function () {
    [, $pub] = configureCloudflare();
    $p = app(App\Services\Video\VideoProvider::class)->playback('vid123', 7200);
    expect($p['hls_url'])->toStartWith('https://customer-abcd1234.cloudflarestream.com/')->toEndWith('/manifest/video.m3u8');
    $token = explode('/', parse_url($p['hls_url'], PHP_URL_PATH))[1];
    [$h, $b, $s] = explode('.', $token);
    expect(openssl_verify("$h.$b", b64url_decode($s), $pub, OPENSSL_ALGO_SHA256))->toBe(1);
    $claims = json_decode(b64url_decode($b), true);
    expect($claims['sub'])->toBe('vid123')->and($claims['kid'])->toBe('kid-1')->and($claims['exp'] - time())->toBeBetween(7190, 7210)
        ->and(json_decode(b64url_decode($h), true))->toBe(['alg' => 'RS256', 'kid' => 'kid-1']);
});

it('caps token lifetime at the provider maximum of 24 hours', function () {
    configureCloudflare();
    $p = app(App\Services\Video\VideoProvider::class)->playback('v', 10 * 86400);
    expect($p['expires_at'] - time())->toBeLessThanOrEqual(86400);
});

it('lets a lecturer authorise a direct resumable upload for their own lesson only', function () {
    configureCloudflare();
    Http::fake(['api.cloudflare.com/*' => Http::response('', 201, ['stream-media-id' => 'cfvid1', 'Location' => 'https://upload.videodelivery.net/tus/abc'])]);
    $lect = $this->makeUser('lecturer');
    $course = Course::factory()->withCurriculum(1, $lect)->create();
    $lesson = $course->lessons()->first();

    $this->actingAs($this->makeUser('lecturer'))->withSession(['mfa_verified' => true])->postJson("/api/v1/manage/lessons/{$lesson->id}/video/upload", ['size_bytes' => 1000])->assertForbidden();
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $this->postJson("/api/v1/manage/lessons/{$lesson->id}/video/upload", ['size_bytes' => 0])->assertUnprocessable();
    $res = $this->postJson("/api/v1/manage/lessons/{$lesson->id}/video/upload", ['size_bytes' => 52_000_000])->assertCreated();
    expect($res->json('data.upload_url'))->toBe('https://upload.videodelivery.net/tus/abc')->and($res->json('data.protocol'))->toBe('tus');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/accounts/acct123/stream?direct_user=true') && $r->hasHeader('Tus-Resumable', '1.0.0') && str_contains($r->header('Upload-Metadata')[0], 'requiresignedurls'));
    expect($lesson->fresh())->video_source->toBe('hosted')->type->toBe('video')->duration_seconds->toBeNull();
    expect(VideoAsset::first())->provider_video_id->toBe('cfvid1')->status->toBe('uploading');
});

it('applies a signed ready webhook: marks the asset ready and sets the lesson duration', function () {
    configureCloudflare();
    $asset = VideoAsset::create(['provider' => 'cloudflare', 'provider_video_id' => 'cfvid1', 'title' => 'L', 'status' => 'processing']);
    $course = Course::factory()->withCurriculum(1)->create();
    $lesson = $course->lessons()->first();
    $lesson->forceFill(['type' => 'video', 'video_source' => 'hosted', 'video_asset_id' => $asset->id])->save();
    $raw = json_encode(['uid' => 'cfvid1', 'readyToStream' => true, 'status' => ['state' => 'ready'], 'duration' => 1234.4]);
    $sig = fn ($t, $body = null) => "time=$t,sig1=".hash_hmac('sha256', $t.'.'.($body ?? $raw), 'whsec_test');

    $post = fn ($header) => $this->call('POST', '/api/v1/webhooks/video/cloudflare', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_WEBHOOK_SIGNATURE' => $header], $raw);
    $post("time=".time().",sig1=deadbeef")->assertUnauthorized();                     // bad signature
    $post($sig(time() - 3600))->assertUnauthorized();                                  // replayed/old
    expect($asset->fresh()->status)->toBe('processing');
    $post($sig(time()))->assertOk();
    expect($asset->fresh())->status->toBe('ready')->duration_seconds->toBe(1235);
    expect($lesson->fresh()->duration_seconds)->toBe(1235);
});

it('issues signed HLS and iframe sources only to entitled, unlocked learners, and not before processing finishes', function () {
    configureCloudflare();
    $course = Course::factory()->published()->withCurriculum(1)->create();
    $lesson = $course->lessons()->first();
    $asset = VideoAsset::create(['provider' => 'cloudflare', 'provider_video_id' => 'cfvid9', 'title' => 'L', 'status' => 'processing']);
    $lesson->forceFill(['type' => 'video', 'video_source' => 'hosted', 'video_asset_id' => $asset->id, 'duration_seconds' => 600])->save();
    $student = $this->makeUser();
    $outsider = $this->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);

    $this->actingAs($student)->postJson("/api/v1/learn/lessons/{$lesson->id}/playback")->assertStatus(409)->assertJsonPath('code', 'video_not_ready');
    $asset->update(['status' => 'ready']);
    $res = $this->postJson("/api/v1/learn/lessons/{$lesson->id}/playback")->assertCreated();
    expect($res->json('data.source.type'))->toBe('hosted')->and($res->json('data.source.hls_url'))->toContain('cloudflarestream.com');
    $this->actingAs($outsider)->postJson("/api/v1/learn/lessons/{$lesson->id}/playback")->assertForbidden();
    // The asset id / provider id is never exposed to learners.
    expect(json_encode($res->json()))->not->toContain('cfvid9');
});

it('reports a provider outage as unavailable rather than leaking an error', function () {
    config(['services.cloudflare_stream' => ['account_id' => null, 'api_token' => null]]);
    $lect = $this->makeUser('lecturer');
    $lesson = Course::factory()->withCurriculum(1, $lect)->create()->lessons()->first();
    $this->actingAs($lect)->withSession(['mfa_verified' => true])->postJson("/api/v1/manage/lessons/{$lesson->id}/video/upload", ['size_bytes' => 1000])->assertStatus(503)->assertJsonPath('code', 'video_unavailable');
});
