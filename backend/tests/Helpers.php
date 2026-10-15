<?php

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/** Paystack-shaped fakes. These mirror the documented response structure; the sandbox smoke test covers the real service. */
function configurePaystack(): void
{
    config(['services.paystack.secret_key' => 'sk_test_unit_secret', 'services.paystack.mode' => 'test', 'services.paystack.base_url' => 'https://api.paystack.co', 'services.paystack.webhook_ip_allowlist' => []]);
}

function fakePaystackInit(): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);   // drop earlier stubs: first-registered stub would otherwise win
    Http::fake([
        'api.paystack.co/transaction/initialize' => fn ($req) => Http::response(['status' => true, 'message' => 'Authorization URL created',
            'data' => ['authorization_url' => 'https://checkout.paystack.com/'.substr(md5($req['reference']), 0, 10), 'access_code' => 'ac_'.substr(md5($req['reference']), 0, 8), 'reference' => $req['reference']]]),
    ]);
}

function gatewayTxn(Payment $p, array $over = []): array
{
    return array_merge(['id' => random_int(100000000, 999999999), 'status' => 'success', 'reference' => $p->reference, 'amount' => $p->amount_minor, 'currency' => 'NGN',
        'channel' => 'card', 'domain' => 'test', 'gateway_response' => 'Successful', 'paid_at' => now()->toIso8601String(), 'metadata' => ['order_id' => $p->order_id]], $over);
}

function fakePaystackVerify(array $data): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'message' => 'Verification successful', 'data' => $data])]);
}

function paidCourse(int $minor = 2500000, string $title = null): Course
{
    return Course::factory()->published()->priced($minor)->withCurriculum(1)->create($title ? ['title' => $title] : []);
}

/** Starts a checkout for $user and returns [Payment, response]. */
function startCheckout($test, User $user, array $courses, ?string $coupon = null): array
{
    fakePaystackInit();
    $res = $test->actingAs($user)->postJson('/api/v1/checkout', ['course_ids' => collect($courses)->pluck('id')->all()] + ($coupon ? ['coupon_code' => $coupon] : []));

    return [Payment::where('reference', $res->json('data.payment.reference'))->first(), $res];
}

function signedWebhook($test, array $payload, ?string $secret = null)
{
    $raw = json_encode($payload);

    return $test->call('POST', '/api/v1/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, $secret ?? 'sk_test_unit_secret'), 'HTTP_ACCEPT' => 'application/json'], $raw);
}
