<?php

use App\Models\Coupon;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Notifications\PaymentConfirmed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => configurePaystack());

it('prices the order on the server and initialises payment in kobo, ignoring client-supplied amounts', function () {
    $course = paidCourse(2500000);
    $student = $this->makeUser();
    fakePaystackInit();
    $res = $this->actingAs($student)->postJson('/api/v1/checkout', ['course_ids' => [$course->id], 'amount' => 1, 'price_minor' => 1, 'total_minor' => 1])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.total_minor', 2500000);
    expect($res->json('data.payment.authorization_url'))->toStartWith('https://checkout.paystack.com/');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transaction/initialize') && $r['amount'] === 2500000 && $r['currency'] === 'NGN' && $r['email'] === $student->email
        && $r['metadata']['order_id'] === $res->json('data.order_id') && str_starts_with($r['reference'], 'GL'));
    expect(Order::count())->toBe(1)->and(CourseEnrollment::count())->toBe(0);   // no access before verified payment
});

it('reuses the open order and payment when checkout is submitted twice', function () {
    $course = paidCourse();
    $student = $this->makeUser();
    [$p1] = startCheckout($this, $student, [$course]);
    [$p2] = startCheckout($this, $student, [$course]);
    expect($p2->id)->toBe($p1->id)->and(Order::count())->toBe(1)->and(Payment::count())->toBe(1);
});

it('rejects unavailable, free and already-owned courses and unverified emails', function () {
    $student = $this->makeUser();
    $draft = Course::factory()->priced(1000)->create();
    $this->actingAs($student)->postJson('/api/v1/checkout', ['course_ids' => [$draft->id]])->assertUnprocessable()->assertJsonPath('code', 'course_unavailable');
    $free = Course::factory()->published()->create();
    $this->postJson('/api/v1/checkout', ['course_ids' => [$free->id]])->assertUnprocessable()->assertJsonPath('code', 'free_course_in_cart');
    $owned = paidCourse();
    CourseEnrollment::create(['course_id' => $owned->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'admin', 'enrolled_at' => now()]);
    $this->postJson('/api/v1/checkout', ['course_ids' => [$owned->id]])->assertStatus(409)->assertJsonPath('code', 'already_enrolled');
    $unverified = $this->makeUser('student', ['email_verified_at' => null]);
    $this->actingAs($unverified)->postJson('/api/v1/checkout', ['course_ids' => [paidCourse()->id]])->assertForbidden();
});

it('settles a verified payment exactly once: order paid, enrolment, receipt and notification', function () {
    Notification::fake();
    $course = paidCourse();
    $student = $this->makeUser();
    [$payment] = startCheckout($this, $student, [$course]);
    fakePaystackVerify(gatewayTxn($payment));

    $this->postJson("/api/v1/payments/{$payment->reference}/verify")->assertOk()->assertJsonPath('data.status', 'success')->assertJsonPath('data.order.status', 'paid');
    $this->postJson("/api/v1/payments/{$payment->reference}/verify")->assertOk()->assertJsonPath('data.status', 'success');   // repeat is a no-op

    expect(CourseEnrollment::where(['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'source' => 'payment'])->count())->toBe(1)
        ->and(Receipt::count())->toBe(1)->and($payment->fresh()->provider_transaction_id)->not->toBeNull();
    Notification::assertSentToTimes($student, PaymentConfirmed::class, 1);
    $this->getJson("/api/v1/learn/lessons/{$course->lessons()->first()->id}")->assertOk();   // learner now has access
});

it('does not grant access when the gateway reports the transaction abandoned, whatever the browser claims', function () {
    $course = paidCourse();
    $student = $this->makeUser();
    [$payment] = startCheckout($this, $student, [$course]);
    fakePaystackVerify(gatewayTxn($payment, ['status' => 'abandoned']));
    $this->postJson("/api/v1/payments/{$payment->reference}/verify", ['status' => 'success', 'paid' => true])->assertOk()->assertJsonPath('data.status', 'abandoned');
    expect(CourseEnrollment::count())->toBe(0)->and(Order::first()->status)->toBe('pending');
});

it('rejects settlement when amount, currency, reference, order or gateway mode do not match', function (array $override, string $reason) {
    $course = paidCourse(2500000);
    $student = $this->makeUser();
    [$payment] = startCheckout($this, $student, [$course]);
    fakePaystackVerify(gatewayTxn($payment, $override));
    $this->postJson("/api/v1/payments/{$payment->reference}/verify")->assertOk()->assertJsonPath('data.status', 'mismatch');
    expect($payment->fresh()->failure_reason)->toContain($reason)->and(CourseEnrollment::count())->toBe(0)->and(Receipt::count())->toBe(0)->and(Order::first()->status)->toBe('pending');
})->with([
    'underpaid amount' => [['amount' => 100000], 'amount_mismatch'],
    'overpaid amount' => [['amount' => 9900000], 'amount_mismatch'],
    'wrong currency' => [['currency' => 'USD'], 'currency_mismatch'],
    'wrong reference' => [['reference' => 'SOMEONE-ELSES'], 'reference_mismatch'],
    'wrong order metadata' => [['metadata' => ['order_id' => 'other']], 'order_mismatch'],
    'live charge in test mode' => [['domain' => 'live'], 'gateway_mode_mismatch'],
    'missing transaction id' => [['id' => null], 'missing_transaction_id'],
]);

it('marks a failed payment and releases the order', function () {
    $course = paidCourse();
    $student = $this->makeUser();
    [$payment] = startCheckout($this, $student, [$course]);
    fakePaystackVerify(gatewayTxn($payment, ['status' => 'failed', 'gateway_response' => 'Insufficient Funds']));
    $this->postJson("/api/v1/payments/{$payment->reference}/verify")->assertOk()->assertJsonPath('data.status', 'failed');
    expect(Order::first()->status)->toBe('failed')->and(CourseEnrollment::count())->toBe(0);
});

it('hides other users\' payments behind a 404', function () {
    [$payment] = startCheckout($this, $this->makeUser(), [paidCourse()]);
    $this->actingAs($this->makeUser())->postJson("/api/v1/payments/{$payment->reference}/verify")->assertNotFound();
});

it('keeps the order pending and returns 202 when the gateway cannot be reached', function () {
    [$payment] = startCheckout($this, $this->makeUser(), [paidCourse()]);
    Http::fake(['api.paystack.co/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('timeout')]);
    $this->postJson("/api/v1/payments/{$payment->reference}/verify")->assertStatus(202)->assertJsonPath('data.status', 'pending');
    expect(CourseEnrollment::count())->toBe(0)->and($payment->fresh()->status)->toBe('initialized');
});

it('fails safely with 503 when initialisation cannot reach the gateway', function () {
    $student = $this->makeUser();
    Http::fake(['api.paystack.co/*' => Http::response(['message' => 'down'], 503)]);
    $this->actingAs($student)->postJson('/api/v1/checkout', ['course_ids' => [paidCourse()->id]])->assertStatus(503)->assertJsonPath('code', 'gateway_unavailable');
    expect(CourseEnrollment::count())->toBe(0);
});

it('flags a second successful charge for the same order as a duplicate for finance', function () {
    $course = paidCourse();
    $student = $this->makeUser();
    [$first] = startCheckout($this, $student, [$course]);
    $second = Payment::create(['order_id' => $first->order_id, 'provider' => 'paystack', 'reference' => 'GLSECOND', 'amount_minor' => $first->amount_minor, 'currency' => 'NGN', 'status' => 'initialized']);
    fakePaystackVerify(gatewayTxn($first));
    $this->postJson("/api/v1/payments/{$first->reference}/verify")->assertOk();
    fakePaystackVerify(gatewayTxn($second));
    $this->postJson("/api/v1/payments/{$second->reference}/verify")->assertOk()->assertJsonPath('data.status', 'mismatch');
    expect($second->fresh()->failure_reason)->toContain('duplicate_payment_for_order')->and(CourseEnrollment::count())->toBe(1)->and(Receipt::count())->toBe(1);
});

it('does not let one gateway transaction settle two payments', function () {
    $student = $this->makeUser();
    [$a] = startCheckout($this, $student, [paidCourse(1000000)]);
    $student2 = $this->makeUser();
    [$b] = startCheckout($this, $student2, [paidCourse(1000000)]);
    $txnId = 555000111;
    fakePaystackVerify(gatewayTxn($a, ['id' => $txnId]));
    $this->actingAs($student)->postJson("/api/v1/payments/{$a->reference}/verify")->assertOk()->assertJsonPath('data.status', 'success');
    fakePaystackVerify(gatewayTxn($b, ['id' => $txnId]));
    $this->actingAs($student2)->postJson("/api/v1/payments/{$b->reference}/verify")->assertOk()->assertJsonPath('data.status', 'mismatch');
    expect($b->fresh()->failure_reason)->toBe('duplicate_gateway_transaction')->and(CourseEnrollment::where('user_id', $student2->id)->count())->toBe(0);
});

// ---------- webhooks ----------
it('rejects webhooks with a missing or wrong signature and never processes them', function () {
    [$payment] = startCheckout($this, $this->makeUser(), [paidCourse()]);
    fakePaystackVerify(gatewayTxn($payment));
    $payload = ['event' => 'charge.success', 'data' => ['reference' => $payment->reference, 'id' => 1, 'status' => 'success']];
    $this->postJson('/api/v1/webhooks/paystack', $payload)->assertUnauthorized();
    signedWebhook($this, $payload, 'sk_test_WRONG')->assertUnauthorized();
    expect(CourseEnrollment::count())->toBe(0)->and($payment->fresh()->status)->toBe('initialized')->and(App\Models\PaymentWebhookEvent::where('signature_valid', false)->count())->toBe(2);
});

it('settles from a signed charge.success webhook and ignores duplicate deliveries', function () {
    $course = paidCourse();
    $student = $this->makeUser();
    [$payment] = startCheckout($this, $student, [$course]);
    fakePaystackVerify(gatewayTxn($payment));
    $payload = ['event' => 'charge.success', 'data' => ['reference' => $payment->reference, 'id' => 4242, 'status' => 'success', 'amount' => $payment->amount_minor]];
    signedWebhook($this, $payload)->assertOk();
    signedWebhook($this, $payload)->assertOk();
    signedWebhook($this, $payload)->assertOk();
    expect($payment->fresh()->status)->toBe('success')->and(CourseEnrollment::count())->toBe(1)->and(Receipt::count())->toBe(1)
        ->and(App\Models\PaymentWebhookEvent::where('event_type', 'charge.success')->count())->toBe(1);
});

it('does not trust the amount or status inside a webhook body; the verify API decides', function () {
    $course = paidCourse(2500000);
    [$payment] = startCheckout($this, $this->makeUser(), [$course]);
    fakePaystackVerify(gatewayTxn($payment, ['status' => 'failed']));   // gateway truth: it failed
    signedWebhook($this, ['event' => 'charge.success', 'data' => ['reference' => $payment->reference, 'id' => 9, 'status' => 'success', 'amount' => 2500000]])->assertOk();
    expect($payment->fresh()->status)->toBe('failed')->and(CourseEnrollment::count())->toBe(0);
});

it('records webhooks for unknown references without failing the delivery', function () {
    configurePaystack();
    signedWebhook($this, ['event' => 'charge.success', 'data' => ['reference' => 'NOT-OURS', 'id' => 1, 'status' => 'success']])->assertOk();
    $e = App\Models\PaymentWebhookEvent::firstOrFail();
    expect($e->processing_error)->toContain('unknown reference')->and($e->processed_at)->not->toBeNull();
});

// ---------- coupons ----------
it('applies a percentage coupon, reserves it, and redeems it on payment', function () {
    $course = paidCourse(2500000);
    $student = $this->makeUser();
    Coupon::create(['code' => 'Grace20', 'type' => 'percent', 'value' => 20, 'max_redemptions' => 5]);
    [$payment, $res] = startCheckout($this, $student, [$course], 'grace20');
    expect($res->json('data.total_minor'))->toBe(2000000)->and($res->json('data.discount_minor'))->toBe(500000)->and($payment->amount_minor)->toBe(2000000);
    expect(App\Models\CouponRedemption::first()->status)->toBe('reserved');
    fakePaystackVerify(gatewayTxn($payment));
    $this->postJson("/api/v1/payments/{$payment->reference}/verify")->assertOk();
    expect(App\Models\CouponRedemption::first()->status)->toBe('redeemed');
});

it('rejects invalid, expired, exhausted and over-used coupons', function () {
    $course = paidCourse(1000000);
    $student = $this->makeUser();
    Coupon::create(['code' => 'EXPIRED', 'type' => 'fixed', 'value' => 1000, 'ends_at' => now()->subDay()]);
    Coupon::create(['code' => 'OFF', 'type' => 'fixed', 'value' => 1000, 'active' => false]);
    Coupon::create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 1000, 'max_redemptions' => 1]);
    $this->actingAs($student);
    foreach (['nope', 'EXPIRED', 'OFF'] as $code) {
        $this->postJson('/api/v1/checkout/preview', ['course_ids' => [$course->id], 'coupon_code' => $code])->assertUnprocessable()->assertJsonPath('code', 'invalid_coupon');
    }
    startCheckout($this, $student, [$course], 'ONCE');
    $other = $this->makeUser();
    $this->actingAs($other)->postJson('/api/v1/checkout/preview', ['course_ids' => [$course->id], 'coupon_code' => 'once'])->assertUnprocessable()->assertJsonPath('code', 'coupon_exhausted');
});

it('releases a reserved coupon when the payment fails', function () {
    $course = paidCourse(1000000);
    Coupon::create(['code' => 'SAVE', 'type' => 'fixed', 'value' => 100000, 'max_redemptions' => 1]);
    $student = $this->makeUser();
    [$payment] = startCheckout($this, $student, [$course], 'SAVE');
    fakePaystackVerify(gatewayTxn($payment, ['status' => 'failed']));
    $this->postJson("/api/v1/payments/{$payment->reference}/verify");
    expect(App\Models\CouponRedemption::first()->status)->toBe('released');
    $other = $this->makeUser();
    $this->actingAs($other)->postJson('/api/v1/checkout/preview', ['course_ids' => [$course->id], 'coupon_code' => 'SAVE'])->assertOk()->assertJsonPath('data.discount_minor', 100000);
});

it('enrols immediately and skips the gateway when a coupon makes the order free', function () {
    $course = paidCourse(1000000);
    Coupon::create(['code' => 'FULL', 'type' => 'percent', 'value' => 100]);
    $student = $this->makeUser();
    Http::fake();
    $res = $this->actingAs($student)->postJson('/api/v1/checkout', ['course_ids' => [$course->id], 'coupon_code' => 'FULL'])->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.total_minor', 0);
    Http::assertNothingSent();
    expect(CourseEnrollment::where('user_id', $student->id)->count())->toBe(1)->and(Payment::first()->status)->toBe('free');
});

it('restricts coupons to the courses they name and never exceeds the price', function () {
    $a = paidCourse(1000000);
    $b = paidCourse(1000000);
    Coupon::create(['code' => 'ONLYA', 'type' => 'fixed', 'value' => 99999999, 'course_ids' => [$a->id]]);
    $student = $this->makeUser();
    $this->actingAs($student)->postJson('/api/v1/checkout/preview', ['course_ids' => [$b->id], 'coupon_code' => 'ONLYA'])->assertUnprocessable()->assertJsonPath('code', 'coupon_not_applicable');
    $this->postJson('/api/v1/checkout/preview', ['course_ids' => [$a->id, $b->id], 'coupon_code' => 'ONLYA'])->assertOk()->assertJsonPath('data.discount_minor', 1000000)->assertJsonPath('data.total_minor', 1000000);
});

// ---------- receipts & reconciliation ----------
it('serves a receipt PDF only to its owner and to finance', function () {
    $student = $this->makeUser();
    [$payment] = startCheckout($this, $student, [paidCourse()]);
    fakePaystackVerify(gatewayTxn($payment));
    $this->postJson("/api/v1/payments/{$payment->reference}/verify")->assertOk();
    $receipt = Receipt::firstOrFail();
    $res = $this->get("/api/v1/student/receipts/{$receipt->id}/pdf")->assertOk();
    expect(substr($res->getContent(), 0, 4))->toBe('%PDF');
    $this->actingAs($this->makeUser())->get("/api/v1/student/receipts/{$receipt->id}/pdf")->assertNotFound();
    $this->actingAs($this->makeUser('finance'))->withSession(['mfa_verified' => true])->get("/api/v1/student/receipts/{$receipt->id}/pdf")->assertOk();
    $this->actingAs($student)->getJson('/api/v1/student/receipts')->assertOk()->assertJsonCount(1, 'data');
});

it('reconciles payments that missed their webhook and expires stale orders', function () {
    $student = $this->makeUser();
    [$payment] = startCheckout($this, $student, [paidCourse()]);
    Payment::whereKey($payment->id)->update(['created_at' => now()->subMinutes(30)]);
    fakePaystackVerify(gatewayTxn($payment));
    $this->artisan('payments:reconcile')->assertSuccessful();
    expect($payment->fresh()->status)->toBe('success')->and(CourseEnrollment::count())->toBe(1);

    [$stale] = startCheckout($this, $this->makeUser(), [paidCourse()]);
    Order::whereKey($stale->order_id)->update(['expires_at' => now()->subHours(3)]);
    Payment::whereKey($stale->id)->update(['status' => 'failed']);
    $this->artisan('payments:reconcile')->assertSuccessful();
    expect(Order::find($stale->order_id)->status)->toBe('expired');
});
