<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Thin, server-side-only Paystack API client. The secret key never leaves the backend. */
class PaystackClient
{
    private function http(): PendingRequest
    {
        $key = config('services.paystack.secret_key');
        if (! $key) {
            throw new GatewayException('Paystack is not configured.', false);
        }

        return Http::baseUrl(rtrim(config('services.paystack.base_url'), '/'))->withToken($key)->acceptJson()->asJson()->timeout(20)->connectTimeout(5);
    }

    /** @return array{authorization_url:string, access_code:string, reference:string} */
    public function initialize(string $email, int $amountMinor, string $currency, string $reference, string $callbackUrl, array $metadata): array
    {
        $data = $this->call(fn () => $this->http()->post('/transaction/initialize', [
            'email' => $email, 'amount' => $amountMinor, 'currency' => $currency, 'reference' => $reference,
            'callback_url' => $callbackUrl, 'metadata' => $metadata,
        ]), 'initialize');
        if (empty($data['authorization_url']) || ($data['reference'] ?? null) !== $reference) {
            throw new GatewayException('Unexpected initialisation response from the gateway.', false);
        }

        return $data;
    }

    /** @return array<string,mixed>|null gateway transaction data, or null when the reference is unknown to the gateway */
    public function verify(string $reference): ?array
    {
        try {
            return $this->call(fn () => $this->http()->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get('/transaction/verify/'.rawurlencode($reference)), 'verify');
        } catch (GatewayException $e) {
            if ($e->httpStatus === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function refund(string $transactionReference, int $amountMinor, string $currency, string $merchantNote): array
    {
        return $this->call(fn () => $this->http()->post('/refund', [
            'transaction' => $transactionReference, 'amount' => $amountMinor, 'currency' => $currency, 'merchant_note' => $merchantNote,
        ]), 'refund');
    }

    private function call(callable $request, string $op): array
    {
        try {
            $res = $request();
        } catch (ConnectionException $e) {
            throw new GatewayException("Gateway unreachable during {$op}.", true);
        }
        if ($res->serverError() || $res->status() === 429) {
            throw new GatewayException("Gateway error ({$res->status()}) during {$op}.", true, $res->status());
        }
        if ($res->failed() || $res->json('status') !== true) {
            throw new GatewayException((string) ($res->json('message') ?: "Gateway rejected {$op}."), false, $res->status());
        }

        return (array) $res->json('data');
    }
}
