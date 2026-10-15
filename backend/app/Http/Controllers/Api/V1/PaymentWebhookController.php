<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\PaymentWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;

class PaymentWebhookController extends Controller
{
    public function paystack(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $secret = (string) config('services.paystack.secret_key');
        $sig = (string) $request->header('x-paystack-signature');
        $allow = config('services.paystack.webhook_ip_allowlist');
        $valid = $secret !== '' && $sig !== '' && hash_equals(hash_hmac('sha512', $raw, $secret), $sig)
            && (empty($allow) || in_array($request->ip(), $allow, true));

        $payload = json_decode($raw, true);
        if (! $valid || ! is_array($payload) || ! isset($payload['event'])) {
            // Keep a record of rejected deliveries (bounded) without processing them.
            PaymentWebhookEvent::create(['provider' => 'paystack', 'event_key' => 'invalid:'.hash('sha256', $raw.microtime()), 'event_type' => 'invalid',
                'payload' => ['note' => 'rejected', 'ip' => $request->ip()], 'signature_valid' => false]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $data = $payload['data'] ?? [];
        // Deterministic key: identical redeliveries collapse to one row.
        $key = hash('sha256', implode('|', [$payload['event'], $data['reference'] ?? $data['transaction_reference'] ?? '', $data['id'] ?? '', $data['status'] ?? '']));
        try {
            $event = DB::transaction(fn () => PaymentWebhookEvent::create(['provider' => 'paystack', 'event_key' => $key, 'event_type' => (string) $payload['event'],
                'reference' => $data['reference'] ?? $data['transaction_reference'] ?? null, 'payload' => $payload, 'signature_valid' => true]));   // savepoint: a duplicate must not poison an outer transaction
        } catch (UniqueConstraintViolationException) {
            $event = PaymentWebhookEvent::where(['provider' => 'paystack', 'event_key' => $key])->firstOrFail();
        }
        if (! $event->processed_at) {
            ProcessPaymentWebhook::dispatch($event->id);
        }

        return response()->json(['received' => true]);   // acknowledge fast; the queue does the work
    }
}
