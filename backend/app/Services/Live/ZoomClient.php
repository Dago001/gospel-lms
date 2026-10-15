<?php

namespace App\Services\Live;

use App\Services\Payments\GatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Zoom Server-to-Server OAuth client. Credentials never leave the backend. */
class ZoomClient
{
    private function token(): string
    {
        $c = config('services.zoom');
        if (! $c['account_id'] || ! $c['client_id'] || ! $c['client_secret']) {
            throw new GatewayException('Zoom is not configured.', false);
        }

        return Cache::remember('zoom.s2s.token', 3000, function () use ($c) {
            try {
                $r = Http::asForm()->withBasicAuth($c['client_id'], $c['client_secret'])->timeout(15)->post($c['oauth_url'], ['grant_type' => 'account_credentials', 'account_id' => $c['account_id']]);
            } catch (ConnectionException) {
                throw new GatewayException('Zoom unreachable.', true);
            }
            if (! $r->successful() || ! $r->json('access_token')) {
                throw new GatewayException('Zoom rejected the credentials.', false, $r->status());
            }

            return $r->json('access_token');
        });
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl(rtrim(config('services.zoom.base_url'), '/'))->withToken($this->token())->acceptJson()->asJson()->timeout(20)->connectTimeout(5);
    }

    private function send(callable $call, string $op): array
    {
        try {
            $r = $call($this->api());
        } catch (ConnectionException) {
            throw new GatewayException("Zoom unreachable during $op.", true);
        }
        if ($r->status() === 401) {
            Cache::forget('zoom.s2s.token');
        }
        if ($r->serverError() || $r->status() === 429) {
            throw new GatewayException("Zoom error ({$r->status()}) during $op.", true, $r->status());
        }
        if ($r->failed()) {
            throw new GatewayException((string) ($r->json('message') ?: "Zoom rejected $op."), false, $r->status());
        }

        return (array) $r->json();
    }

    /** @return array{id:string, join_url:string, start_url:string} */
    public function createMeeting(string $topic, \DateTimeInterface $startsAtUtc, int $durationMinutes): array
    {
        $d = $this->send(fn ($h) => $h->post('/users/'.config('services.zoom.host_user').'/meetings', [
            'topic' => mb_substr($topic, 0, 200), 'type' => 2, 'start_time' => $startsAtUtc->format('Y-m-d\TH:i:s\Z'), 'duration' => $durationMinutes, 'timezone' => 'UTC',
            'settings' => ['approval_type' => 0, 'registration_type' => 1, 'waiting_room' => true, 'join_before_host' => false, 'mute_upon_entry' => true, 'auto_recording' => 'cloud', 'registrants_email_notification' => false],
        ]), 'create meeting');

        return ['id' => (string) $d['id'], 'join_url' => (string) $d['join_url'], 'start_url' => (string) ($d['start_url'] ?? '')];
    }

    public function updateMeeting(string $id, string $topic, \DateTimeInterface $startsAtUtc, int $durationMinutes): void
    {
        $this->send(fn ($h) => $h->patch("/meetings/$id", ['topic' => mb_substr($topic, 0, 200), 'start_time' => $startsAtUtc->format('Y-m-d\TH:i:s\Z'), 'duration' => $durationMinutes, 'timezone' => 'UTC']), 'update meeting');
    }

    public function deleteMeeting(string $id): void
    {
        try {
            $this->send(fn ($h) => $h->delete("/meetings/$id"), 'delete meeting');
        } catch (GatewayException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;                      // already gone is fine
            }
        }
    }

    /** Per-learner join link; calling twice for the same email returns the same registrant. @return array{id:string, join_url:string} */
    public function registrant(string $meetingId, string $email, string $firstName, string $lastName): array
    {
        $d = $this->send(fn ($h) => $h->post("/meetings/$meetingId/registrants", ['email' => $email, 'first_name' => mb_substr($firstName, 0, 60) ?: 'Student', 'last_name' => mb_substr($lastName, 0, 60) ?: '-', 'auto_approve' => true]), 'register learner');

        return ['id' => (string) ($d['registrant_id'] ?? ''), 'join_url' => (string) $d['join_url']];
    }

    /** Authoritative post-meeting report. @return array<int,array{email:string, minutes:int}> total minutes per participant email */
    public function participantMinutes(string $meetingId): array
    {
        $minutes = [];
        $next = null;
        do {
            $d = $this->send(fn ($h) => $h->get("/report/meetings/$meetingId/participants", array_filter(['page_size' => 300, 'next_page_token' => $next])), 'participant report');
            foreach ($d['participants'] ?? [] as $p) {
                $email = strtolower((string) ($p['user_email'] ?? ''));
                if ($email !== '') {
                    $minutes[$email] = ($minutes[$email] ?? 0) + (int) ceil(($p['duration'] ?? 0) / 60);   // Zoom reports seconds; sessions are summed across rejoins
                }
            }
            $next = $d['next_page_token'] ?? null;
        } while ($next);

        return $minutes;
    }

    /** Zoom signs every webhook: v0=HMAC_SHA256(secret, "v0:{timestamp}:{body}"). Replay window 5 minutes. */
    public function verifyWebhook(string $body, ?string $timestamp, ?string $signature): bool
    {
        $secret = (string) config('services.zoom.webhook_secret_token');
        if ($secret === '' || ! $timestamp || ! $signature || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        return hash_equals('v0='.hash_hmac('sha256', "v0:$timestamp:$body", $secret), $signature);
    }

    public function validationResponse(string $plainToken): array
    {
        return ['plainToken' => $plainToken, 'encryptedToken' => hash_hmac('sha256', $plainToken, (string) config('services.zoom.webhook_secret_token'))];
    }
}
